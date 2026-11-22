<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SettlementLine;
use Illuminate\Support\Str;

/**
 * Payment reconciliation (SOW 2.14): a settlement report from the payment provider (CSV) is matched line by
 * line against our paid orders, by payment reference first, then order number, then checked on amount.
 * Column names are found by their header (reference / job / order / amount / date), so any export works.
 */
class Settlements
{
    /** @return array{batch: string, lines: int, matched: int, amount: int, unmatched: int} */
    public function import(string $csv): array
    {
        $rows = array_values(array_filter(array_map(fn ($l) => str_getcsv($l), preg_split('/\R/', trim($csv))), fn ($r) => array_filter($r) !== []));
        if (count($rows) < 2) {
            throw new \DomainException('The file needs a header row and at least one line.');
        }
        $head = array_map(fn ($h) => Str::lower(trim((string) $h)), $rows[0]);
        $col = function (array $words) use ($head): ?int {
            foreach ($head as $i => $h) {
                foreach ($words as $w) {
                    if (str_contains($h, $w)) {
                        return $i;
                    }
                }
            }

            return null;
        };
        $ref = $col(['payment job', 'job ref', 'reference', 'transaction id', 'payment id']);
        $num = $col(['order number', 'order ref', 'order', 'merchant ref']);
        $amt = $col(['amount', 'value', 'gross']);
        $date = $col(['settle', 'date']);
        if ($amt === null || ($ref === null && $num === null)) {
            throw new \DomainException('Couldn’t find the amount and a reference or order number column.');
        }
        $batch = now('Europe/London')->format('Ymd-His').'-'.Str::lower(Str::random(4));
        $out = ['batch' => $batch, 'lines' => 0, 'matched' => 0, 'amount' => 0, 'unmatched' => 0];
        foreach (array_slice($rows, 1) as $r) {
            $reference = $ref !== null ? trim((string) ($r[$ref] ?? '')) : null;
            $number = $num !== null ? trim((string) ($r[$num] ?? '')) : null;
            $pence = (int) round(((float) preg_replace('/[^0-9.\-]/', '', (string) ($r[$amt] ?? '0'))) * 100);
            $order = ($reference ? Order::query()->where('gateway_ref', $reference)->first() : null)
                ?? ($number ? Order::query()->where('number', $number)->first() : null);
            $match = ! $order ? 'unmatched' : ($order->card_pence === abs($pence) ? 'matched' : 'amount');
            $settled = null;
            if ($date !== null && ($d = trim((string) ($r[$date] ?? ''))) !== '') {
                try {
                    $settled = \Illuminate\Support\Carbon::parse(str_replace('/', '-', $d))->toDateString();
                } catch (\Throwable) {
                    $settled = null;
                }
            }
            SettlementLine::query()->create(['batch' => $batch, 'reference' => $reference ?: null, 'order_number' => $number ?: $order?->number,
                'amount_pence' => $pence, 'settled_on' => $settled, 'order_id' => $order?->id, 'match' => $match]);
            $out['lines']++;
            $out[$match === 'matched' ? 'matched' : ($match === 'amount' ? 'amount' : 'unmatched')] = ($out[$match === 'matched' ? 'matched' : ($match === 'amount' ? 'amount' : 'unmatched')] ?? 0) + 1;
        }

        return $out;
    }

    /** Paid card orders in the period with no settlement line yet. */
    public function unsettled(\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to)
    {
        return Order::query()->where('status', 'paid')->where('card_pence', '>', 0)->whereBetween('paid_at', [$from, $to])
            ->whereNotIn('id', SettlementLine::query()->whereNotNull('order_id')->select('order_id'))->orderBy('paid_at')->limit(200)->get();
    }
}

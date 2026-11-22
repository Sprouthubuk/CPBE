<?php

namespace App\Support;

use App\Models\Competition;
use Illuminate\Support\Facades\DB;

/**
 * Instant-win prizes from a CSV (SOW 2.5): name, value (pounds), type, quantity. Only before publishing:
 * which tickets win is then fixed by Verity from its committed seed, so the file never picks ticket numbers.
 */
final class PrizeTable
{
    public const TYPES = ['site_credit', 'cash', 'physical'];

    /** @return list<array{name: string, value_pence: int, type: string, quantity: int}> */
    public static function parse(string $csv): array
    {
        $rows = Csv::rows($csv, 500);
        if (! $rows) {
            throw new \InvalidArgumentException('The file has no prizes.');
        }
        $out = [];
        foreach ($rows as $i => $r) {
            $line = $i + 2;
            $name = mb_substr($r['name'] ?? '', 0, 120);
            $value = str_replace(['£', ','], '', $r['value'] ?? '');
            $type = strtolower(str_replace(' ', '_', $r['type'] ?? 'site_credit')) ?: 'site_credit';
            $qty = $r['quantity'] ?? '';
            if ($name === '') {
                throw new \InvalidArgumentException("Row {$line}: a prize needs a name.");
            }
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $value) || (float) $value <= 0) {
                throw new \InvalidArgumentException("Row {$line}: the value must be an amount in pounds, e.g. 25 or 2.50.");
            }
            if (! in_array($type, self::TYPES, true)) {
                throw new \InvalidArgumentException("Row {$line}: the type must be site_credit, cash or physical.");
            }
            if (! ctype_digit($qty) || (int) $qty < 1) {
                throw new \InvalidArgumentException("Row {$line}: the quantity must be a whole number of 1 or more.");
            }
            $out[] = ['name' => $name, 'value_pence' => (int) round((float) $value * 100), 'type' => $type, 'quantity' => (int) $qty];
        }

        return $out;
    }

    /** Replace an unpublished instant-win competition's prizes. */
    public static function replace(Competition $c, array $prizes): void
    {
        if ($c->verity_ref !== null || ! $c->isInstant()) {
            throw new \InvalidArgumentException('Prizes can only be imported into an instant-win competition before it is published.');
        }
        $total = array_sum(array_column($prizes, 'quantity'));
        if ($total > (int) $c->max_tickets) {
            throw new \InvalidArgumentException("That’s {$total} prizes for {$c->max_tickets} tickets.");
        }
        DB::transaction(function () use ($c, $prizes) {
            $c->prizes()->delete();
            foreach ($prizes as $i => $p) {
                $c->prizes()->create($p + ['sort' => $i]);
            }
        });
    }
}

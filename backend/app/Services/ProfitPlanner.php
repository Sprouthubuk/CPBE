<?php

namespace App\Services;

/**
 * The competition profit planner (SOW 2.14): model a competition before launch from prize cost, ticket price,
 * maximum tickets, bundles, promotions, discounts, marketing, VAT and payment fees; see break-even, profit at
 * each sell-through level and the sales needed for target margins. Money is whole pence.
 */
final class ProfitPlanner
{
    public const DEFAULTS = [
        'name' => 'Option', 'prizeCost' => 100000, 'ticketPrice' => 99, 'maxTickets' => 2000,
        'freeSharePct' => 10,     // share of tickets given free in bundles
        'promoSharePct' => 2,     // share of tickets given as vouchers / bonus
        'discountPct' => 0,       // average promo-code discount on paid tickets
        'marketing' => 10000,     // marketing spend for this competition
        'vatPct' => 0,            // VAT (or other tax) payable on ticket revenue, if any
        'feeBp' => 200,           // payment fee: basis points of card money (200 = 2%)
        'feeFixed' => 20,         // and pence per order
        'avgOrder' => 1000,       // average order value, for the per-order fee
    ];

    /** @return array{levels: list<array<string, mixed>>, breakEvenPct: float|null, breakEvenTickets: int|null, for20: array|null, for30: array|null, fullProfit: int} */
    public static function model(array $in): array
    {
        $i = array_merge(self::DEFAULTS, array_filter($in, fn ($v) => $v !== null && $v !== ''));
        foreach (array_keys(self::DEFAULTS) as $k) {
            if ($k !== 'name') {
                $i[$k] = max(0, (int) $i[$k]);
            }
        }
        $levels = [];
        foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $pct) {
            $levels[] = ['pct' => $pct] + self::at($i, (int) floor($i['maxTickets'] * $pct / 100));
        }
        $breakEven = null;
        for ($t = 1; $t <= $i['maxTickets']; $t += max(1, intdiv($i['maxTickets'], 2000))) {
            if (self::at($i, $t)['profit'] >= 0) {
                $breakEven = $t;
                break;
            }
        }
        $target = function (int $margin) use ($i) {
            for ($t = 1; $t <= $i['maxTickets']; $t += max(1, intdiv($i['maxTickets'], 2000))) {
                $r = self::at($i, $t);
                if ($r['revenue'] > 0 && $r['profit'] * 100 >= $margin * $r['revenue']) {
                    return ['tickets' => $t, 'pct' => round($t * 100 / max(1, $i['maxTickets']), 1)];
                }
            }

            return null;
        };

        return [
            'levels' => $levels,
            'breakEvenTickets' => $breakEven,
            'breakEvenPct' => $breakEven !== null ? round($breakEven * 100 / max(1, $i['maxTickets']), 1) : null,
            'for20' => $target(20), 'for30' => $target(30),
            'fullProfit' => self::at($i, $i['maxTickets'])['profit'],
            'effectivePrice' => self::at($i, $i['maxTickets'])['effectivePrice'],
        ];
    }

    /** Money at $sold tickets (all entries, paid and free). */
    private static function at(array $i, int $sold): array
    {
        $paid = (int) floor($sold * max(0, 100 - $i['freeSharePct'] - $i['promoSharePct']) / 100);
        $revenue = (int) round($paid * $i['ticketPrice'] * (100 - min(100, $i['discountPct'])) / 100);
        $vat = intdiv($revenue * $i['vatPct'], 100 + $i['vatPct']);
        $orders = $i['avgOrder'] > 0 ? (int) ceil($revenue / $i['avgOrder']) : 0;
        $fees = intdiv($revenue * $i['feeBp'], 10000) + $orders * $i['feeFixed'];
        $costs = $i['prizeCost'] + $i['marketing'] + $vat + $fees;

        return ['sold' => $sold, 'paid' => $paid, 'revenue' => $revenue, 'vat' => $vat, 'fees' => $fees, 'costs' => $costs,
            'profit' => $revenue - $costs, 'margin' => $revenue > 0 ? round(($revenue - $costs) * 100 / $revenue, 1) : null,
            'effectivePrice' => $sold > 0 ? intdiv($revenue, $sold) : 0];
    }
}

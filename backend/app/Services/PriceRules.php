<?php

namespace App\Services;

use App\Models\Competition;
use App\Models\CompetitionPriceRule;
use App\Models\OrderLine;

/**
 * Amendment 5: what a competition's automatic rules give right now. Each setting (price, bonus tickets,
 * bundles on offer, tickets on sale) comes from the first active rule, in order, whose dates and trigger
 * match; with none, the competition's own price and bundles apply. Applied only here, read by Pricing,
 * Bundles and the presenter, so the page, the basket and checkout always agree.
 *
 * The maximum number of tickets published to Verity never changes: a release rule (live draws only)
 * holds some of them back for later, and is never applied to free postal entries.
 */
final class PriceRules
{
    public function __construct(private CompetitionState $state) {}

    /**
     * @return array{unitPence: int, basePence: int, bonus: ?array{buy: int, get: int}, bundleTierIds: ?list<int>,
     *               releaseLeft: ?int, label: ?string, ruleId: ?int, until: ?string}
     */
    public function now(Competition $c): array
    {
        $out = ['unitPence' => (int) $c->price_pence, 'basePence' => (int) $c->price_pence, 'bonus' => null, 'bundleTierIds' => null,
            'releaseLeft' => null, 'label' => null, 'ruleId' => null, 'until' => null];
        $rules = $c->relationLoaded('priceRules') ? $c->priceRules : $c->priceRules()->get();
        $got = [];
        foreach ($rules as $r) {
            if (! $r->is_active || ! $this->matches($c, $r)) {
                continue;
            }
            $applied = false;
            if ($r->price_pence !== null && ! isset($got['price'])) {
                $out['unitPence'] = (int) $r->price_pence;
                $got['price'] = $applied = true;
            }
            if ($r->bonus_buy !== null && ! isset($got['bonus'])) {
                $out['bonus'] = ['buy' => (int) $r->bonus_buy, 'get' => (int) $r->bonus_get];
                $got['bonus'] = $applied = true;
            }
            if ($r->bundle_tier_ids !== null && ! isset($got['bundles'])) {
                $out['bundleTierIds'] = array_values(array_map('intval', $r->bundle_tier_ids));
                $got['bundles'] = $applied = true;
            }
            if ($r->release_pct !== null && ! isset($got['release']) && $c->hasDraw() && ! $c->isInstant()) {
                $onSale = intdiv((int) $c->max_tickets * (int) $r->release_pct, 100);
                $out['releaseLeft'] = max(0, $onSale - $this->state->sold($c));
                $got['release'] = $applied = true;
            }
            // The rule players see: the first one that changes the price or adds bonus tickets.
            if ($applied && $out['ruleId'] === null && (isset($got['price']) || isset($got['bonus']))) {
                $out['label'] = $r->name;
                $out['ruleId'] = $r->id;
                $out['until'] = $this->until($c, $r);
            }
        }

        return $out;
    }

    /** Free tickets a bonus rule adds to this many single tickets. */
    public static function bonusFor(?array $bonus, int $singles): int
    {
        return $bonus ? intdiv($singles, $bonus['buy']) * $bonus['get'] : 0;
    }

    private function matches(Competition $c, CompetitionPriceRule $r): bool
    {
        if (($r->starts_at && now()->lt($r->starts_at)) || ($r->ends_at && now()->gte($r->ends_at))) {
            return false;
        }
        $n = (int) $r->trigger_value;
        $hoursLeft = $c->closes_at ? now()->diffInMinutes($c->closes_at, false) / 60 : INF;

        return match ($r->trigger) {
            'always' => true,
            'hours_left_more' => $hoursLeft > $n,
            'hours_left_at_most' => $hoursLeft <= $n,
            'sold_pct_below' => $this->state->soldPercent($c) < $n,
            'sold_pct_at_least' => $this->state->soldPercent($c) >= $n,
            'sold_24h_at_least' => $this->soldLastDay($c) >= $n,
            default => false,
        };
    }

    /** When a time-based rule stops applying, for the page's "ends in". */
    private function until(Competition $c, CompetitionPriceRule $r): ?string
    {
        $times = array_filter([$r->ends_at, $r->trigger === 'hours_left_more' && $c->closes_at ? $c->closes_at->copy()->subHours((int) $r->trigger_value) : null]);

        return $times ? collect($times)->min()->toIso8601ZuluString() : null;
    }

    private function soldLastDay(Competition $c): int
    {
        return (int) OrderLine::query()->where('competition_id', $c->id)
            ->whereHas('order', fn ($query) => $query->where('status', 'paid')->where('paid_at', '>=', now()->subDay()))->sum('quantity');
    }
}

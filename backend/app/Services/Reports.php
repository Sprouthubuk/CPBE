<?php

namespace App\Services;

use App\Models\Competition;
use App\Models\CostEntry;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\LoyaltyEntry;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PostalEntry;
use App\Models\PrizeClaim;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Setting;
use App\Models\SettlementLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Sprouthub\Verity\Models\InstantPrize;
use Sprouthub\Verity\Models\Winner;

/**
 * Reporting and business intelligence (SOW 2.14). Every figure comes from the platform's own records
 * (orders, entries, wins, ledgers) plus costs entered by hand (CostEntry), which are kept apart as actual,
 * estimated or forecast. Money is whole pence. Periods are UK days.
 *
 * Definitions used throughout:
 * - Gross sales: what paid ticket orders cost (total_pence), before refunds. Memberships are shown separately.
 * - Real money: card + wallet cash + winnings; bonus credit is promotional and never counted as revenue.
 * - Net revenue: real money from ticket orders and memberships, less refunds and lost chargebacks.
 * - Costs: prizes (instant wins paid + draw prize costs for draws that finished) + estimated payment fees
 *   + bonus credit spent + manual costs. Profit = net revenue − costs.
 */
class Reports
{
    public const PERIODS = ['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days', 'year' => 'Last 12 months', 'all' => 'All time'];

    public function __construct(private CompetitionState $state) {}

    /** @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon} from, to, previous from, previous to (UTC) */
    public function range(string $period): array
    {
        $now = now('Europe/London');
        $from = match ($period) {
            'today' => $now->copy()->startOfDay(),
            'week' => $now->copy()->startOfDay()->subDays(6),
            'month' => $now->copy()->startOfDay()->subDays(29),
            'year' => $now->copy()->startOfDay()->subYear()->addDay(),
            default => Carbon::create(2020, 1, 1, 0, 0, 0, 'Europe/London'),
        };
        $len = $from->diffInSeconds($now);

        return [$from->copy()->utc(), $now->copy()->utc(), $from->copy()->subSeconds((int) $len)->utc(), $from->copy()->utc()];
    }

    /** Payment provider fee estimate for card money (setting: basis points + pence per payment, e.g. 200 + 20). */
    public static function cardFee(int $cardPence, int $payments): int
    {
        return intdiv($cardPence * max(0, Setting::int('payment_fee_bp')), 10000) + $payments * max(0, Setting::int('payment_fee_fixed_pence'));
    }

    private function paid(Carbon $from, Carbon $to, string $type = 'tickets')
    {
        return Order::query()->where('order_type', $type)->whereIn('status', ['paid', 'refunded'])->whereBetween('paid_at', [$from, $to]);
    }

    /** Company level: sales, revenue, entries by type, costs, profit and margin, against the previous period and target. */
    public function company(Carbon $from, Carbon $to): array
    {
        $o = fn () => $this->paid($from, $to);
        $gross = (int) $o()->sum('total_pence');
        $real = (int) $o()->selectRaw('COALESCE(SUM(card_pence + paid_deposit_pence + paid_winnings_pence), 0) s')->value('s');
        $bonusUsed = (int) $o()->sum('paid_bonus_pence');
        $discounts = (int) $o()->sum('discount_pence');
        $refunds = (int) $o()->where('status', 'refunded')->selectRaw('COALESCE(SUM(card_pence + paid_deposit_pence + paid_winnings_pence), 0) s')->value('s');
        $membershipRevenue = (int) $this->paid($from, $to, 'membership')->selectRaw('COALESCE(SUM(card_pence + paid_deposit_pence + paid_winnings_pence), 0) s')->value('s');
        $cardPence = (int) Order::query()->whereIn('status', ['paid', 'refunded'])->whereBetween('paid_at', [$from, $to])->sum('card_pence');
        $cardPayments = Order::query()->whereIn('status', ['paid', 'refunded'])->whereBetween('paid_at', [$from, $to])->where('card_pence', '>', 0)->count();

        $lines = OrderLine::query()->whereHas('order', fn ($q) => $q->whereIn('status', ['paid', 'refunded'])->whereBetween('paid_at', [$from, $to]));
        $paidEntries = (int) (clone $lines)->whereHas('order', fn ($q) => $q->where('total_pence', '>', 0))->sum('paid_quantity');
        $bundleFree = (int) (clone $lines)->sum('free_quantity');
        $bonusEntries = (int) (clone $lines)->sum('bonus_quantity');
        $promoEntries = (int) (clone $lines)->whereIn('source', ['voucher', 'loyalty', 'membership'])->sum('quantity')
            + (int) (clone $lines)->whereHas('order', fn ($q) => $q->where('total_pence', 0))->whereNull('source')->sum('quantity');
        $postal = PostalEntry::query()->where('status', 'entered')->whereBetween('received_at', [$from, $to])->count();

        $instantPrizes = (int) Winner::query()->where('type', 'instant')->where('status', 'active')->whereBetween('created_at', [$from, $to])->sum('prize_value_pence');
        $drawPrizes = (int) Competition::query()->where('type', 'draw')->whereNotNull('ended_at')->whereBetween('ended_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(COALESCE(prize_cost_pence, end_prize_value_pence, 0)), 0) s')->value('s');
        $manual = CostEntry::query()->whereDate('date', '>=', $from->copy()->timezone('Europe/London')->toDateString())->whereDate('date', '<=', $to->copy()->timezone('Europe/London')->toDateString())->get();
        $fees = self::cardFee($cardPence, $cardPayments);
        $net = $real + $membershipRevenue - $refunds;
        $costs = [
            'Instant-win prizes' => $instantPrizes,
            'Draw prizes' => $drawPrizes,
            'Payment fees (estimated)' => $fees,
            'Bonus credit used' => $bonusUsed,
        ];
        foreach (CostEntry::CATEGORIES as $k => $label) {
            $costs[$label.' (entered)'] = (int) $manual->where('category', $k)->sum('amount_pence');
        }
        $totalCosts = array_sum($costs);
        $profit = $net - $totalCosts;

        return [
            'grossSales' => $gross, 'realMoney' => $real, 'bonusUsed' => $bonusUsed, 'discounts' => $discounts, 'refunds' => $refunds,
            'membershipRevenue' => $membershipRevenue, 'netRevenue' => $net, 'orders' => $o()->count(),
            'entries' => ['Paid' => $paidEntries, 'Bundle free' => $bundleFree, 'Bonus' => $bonusEntries, 'Promotional (vouchers)' => $promoEntries, 'Postal' => $postal],
            'costs' => $costs, 'totalCosts' => $totalCosts,
            'costBasis' => ['actual' => (int) $manual->where('basis', 'actual')->sum('amount_pence'), 'estimated' => (int) $manual->where('basis', 'estimated')->sum('amount_pence') + $fees,
                'forecast' => (int) $manual->where('basis', 'forecast')->sum('amount_pence')],
            'profit' => $profit, 'marginPct' => $net > 0 ? round($profit * 100 / $net, 1) : null,
        ];
    }

    /** Sales for today, this week, month, year and all time (the headline row). */
    public function headline(): array
    {
        $out = [];
        foreach (['today', 'week', 'month', 'year', 'all'] as $p) {
            [$f, $t] = $this->range($p);
            $out[$p] = (int) $this->paid($f, $t)->sum('total_pence');
        }
        $target = Setting::int('monthly_revenue_target_pence');
        $monthStart = now('Europe/London')->startOfMonth()->utc();
        $thisMonth = (int) $this->paid($monthStart, now())->sum('total_pence');

        return $out + ['target' => $target ?: null, 'thisMonth' => $thisMonth,
            'targetPct' => $target ? round($thisMonth * 100 / $target, 1) : null];
    }

    /**
     * Competition level: live and recently closed competitions with sales speed, forecast sell-through and
     * sell-out time, final revenue forecast, profit or loss and break-even.
     */
    public function competitions(): Collection
    {
        $since = now()->subDay();

        return Competition::query()->published()->where('closes_at', '>', now()->subDays(14))->with('prizes')->get()->map(function (Competition $c) use ($since) {
            $sold = $this->state->sold($c);
            $lines = OrderLine::query()->where('competition_id', $c->id)->whereHas('order', fn ($q) => $q->where('status', 'paid'));
            $revenue = (int) (clone $lines)->sum('line_pence');
            $last24 = (int) (clone $lines)->whereHas('order', fn ($q) => $q->where('paid_at', '>=', $since))->sum('quantity');
            $perHour = $last24 / 24;
            $hoursLeft = max(0, now()->diffInSeconds($c->closes_at, false) / 3600);
            $left = max(0, $c->max_tickets - $sold);
            $forecastSold = (int) min($c->max_tickets, $sold + floor($perHour * $hoursLeft));
            $sellOutAt = $perHour > 0 && $left > 0 ? now()->addSeconds((int) ($left / $perHour * 3600)) : null;
            $prizeCost = $c->isInstant()
                ? (int) $c->prizes->sum(fn ($p) => $p->value_pence * $p->quantity) + (int) ($c->end_prize_value_pence ?? 0) * max(1, (int) $c->end_prize_count)
                : (int) ($c->prize_cost_pence ?? $c->end_prize_value_pence ?? 0);
            $paidOut = $c->isInstant() && $c->verity_ref ? (int) Winner::query()->whereHas('competition', fn ($q) => $q->where('ref', $c->verity_ref))
                ->where('type', 'instant')->where('status', 'active')->sum('prize_value_pence') : 0;
            $breakEven = $c->price_pence > 0 ? (int) ceil($prizeCost / $c->price_pence) : null;
            $forecastRevenue = $sold > 0 ? (int) round($revenue / max(1, $sold) * $forecastSold) : $forecastSold * $c->price_pence;

            return [
                'id' => $c->id, 'title' => $c->title, 'slug' => $c->slug, 'type' => $c->type, 'status' => $this->state->status($c),
                'closesAt' => $c->closes_at, 'sold' => $sold, 'max' => $c->max_tickets, 'soldPct' => $c->max_tickets ? round($sold * 100 / $c->max_tickets, 1) : 0,
                'revenue' => $revenue, 'perHour' => round($perHour, 1), 'sellOutAt' => $sellOutAt,
                'forecastSold' => $forecastSold, 'forecastPct' => $c->max_tickets ? round($forecastSold * 100 / $c->max_tickets, 1) : 0,
                'forecastRevenue' => $forecastRevenue, 'prizeCost' => $prizeCost, 'paidOut' => $paidOut,
                'breakEvenTickets' => $breakEven, 'profitSoFar' => $revenue - ($c->isInstant() ? $paidOut : 0),
                'forecastProfit' => $forecastRevenue - $prizeCost,
                'atRisk' => $breakEven !== null && $forecastSold < $breakEven && $hoursLeft > 0,
            ];
        })->sortByDesc('revenue')->values();
    }

    /** Customer level: new, active, repeat and inactive players, AOV, lifetime value, retention and repeat purchase. */
    public function customers(Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $buyers = fn (Carbon $f, Carbon $t) => Order::query()->where('order_type', 'tickets')->where('status', 'paid')->where('total_pence', '>', 0)
            ->whereBetween('paid_at', [$f, $t])->distinct()->pluck('user_id');
        $now = $buyers($from, $to);
        $prev = $buyers($prevFrom, $prevTo);
        $ordersNow = $this->paid($from, $to)->where('total_pence', '>', 0)->get(['user_id', 'total_pence']);
        $counts = $ordersNow->countBy('user_id');
        $everPaid = Order::query()->where('order_type', 'tickets')->where('status', 'paid')->where('total_pence', '>', 0);
        $lifetime = (clone $everPaid)->selectRaw('user_id, SUM(card_pence + paid_deposit_pence + paid_winnings_pence) s')->groupBy('user_id')->pluck('s');
        $recent = (clone $everPaid)->where('paid_at', '>=', now()->subDays(30))->distinct()->pluck('user_id');
        $everIds = (clone $everPaid)->distinct()->pluck('user_id');

        return [
            'registered' => User::query()->where('is_admin', false)->where('is_staff', false)->count(),
            'new' => User::query()->where('is_admin', false)->where('is_staff', false)->whereBetween('created_at', [$from, $to])->count(),
            'active' => $now->count(),
            'repeat' => $counts->filter(fn ($n) => $n > 1)->count(),
            'inactive' => $everIds->diff($recent)->count(),
            'aov' => $ordersNow->count() ? intdiv((int) $ordersNow->sum('total_pence'), $ordersNow->count()) : 0,
            'ltv' => $lifetime->count() ? intdiv((int) $lifetime->sum(), $lifetime->count()) : 0,
            'retentionPct' => $prev->count() ? round($prev->intersect($now)->count() * 100 / $prev->count(), 1) : null,
            'repeatPct' => $now->count() ? round($counts->filter(fn ($n) => $n > 1)->count() * 100 / $now->count(), 1) : null,
        ];
    }

    /** Marketing: spend (entered costs), revenue and new customers by channel, return on ad spend, cost per new customer. */
    public function marketing(Carbon $from, Carbon $to): array
    {
        [$fd, $td] = [$from->copy()->timezone('Europe/London')->toDateString(), $to->copy()->timezone('Europe/London')->toDateString()];
        $spend = CostEntry::query()->where('category', 'marketing')->whereDate('date', '>=', $fd)->whereDate('date', '<=', $td)->get()->groupBy(fn ($c) => $c->channel ?: 'other')
            ->map(fn ($g) => (int) $g->sum('amount_pence'));
        $orders = $this->paid($from, $to)->where('total_pence', '>', 0)->get(['user_id', 'channel', 'card_pence', 'paid_deposit_pence', 'paid_winnings_pence', 'paid_at']);
        $revenue = $orders->groupBy(fn ($o) => $o->channel ?: 'direct')->map(fn ($g) => (int) $g->sum(fn ($o) => $o->card_pence + $o->paid_deposit_pence + $o->paid_winnings_pence));
        // A new customer: their first ever paid order falls in the period; credited to that order's channel.
        $firsts = Order::query()->where('order_type', 'tickets')->where('status', 'paid')->where('total_pence', '>', 0)
            ->selectRaw('user_id, MIN(paid_at) first_at')->groupBy('user_id')->havingBetween('first_at', [$from, $to])->pluck('first_at', 'user_id');
        $newBy = $orders->filter(fn ($o) => isset($firsts[$o->user_id]) && $o->paid_at->equalTo(Carbon::parse($firsts[$o->user_id])))
            ->unique('user_id')->groupBy(fn ($o) => $o->channel ?: 'direct')->map->count();
        $channels = collect([...$spend->keys(), ...$revenue->keys(), ...$newBy->keys()])->unique()->values();
        $rows = $channels->map(fn ($ch) => [
            'channel' => $ch, 'spend' => $spend[$ch] ?? 0, 'revenue' => $revenue[$ch] ?? 0, 'newCustomers' => $newBy[$ch] ?? 0,
            'roas' => ($spend[$ch] ?? 0) > 0 ? round(($revenue[$ch] ?? 0) / $spend[$ch], 2) : null,
            'cpa' => ($newBy[$ch] ?? 0) > 0 && ($spend[$ch] ?? 0) > 0 ? intdiv($spend[$ch], $newBy[$ch]) : null,
        ])->sortByDesc('revenue')->values();
        $totalSpend = (int) $spend->sum();
        $totalNew = (int) $newBy->sum();

        return ['rows' => $rows->all(), 'spend' => $totalSpend, 'revenue' => (int) $revenue->sum(), 'newCustomers' => $totalNew,
            'roas' => $totalSpend > 0 ? round($revenue->sum() / $totalSpend, 2) : null, 'cpa' => $totalNew && $totalSpend ? intdiv($totalSpend, $totalNew) : null];
    }

    /** Operations: things that need attention now. */
    public function operations(Carbon $from, Carbon $to): array
    {
        $instant = InstantPrize::query()->selectRaw('COALESCE(SUM(quantity), 0) q')->value('q');
        $won = Winner::query()->where('type', 'instant')->where('status', 'active')->count();

        return [
            'failedPayments' => Order::query()->where('status', 'failed')->whereBetween('created_at', [$from, $to])->count(),
            'pendingOrders' => Order::query()->whereIn('status', Order::UNPAID)->count(),
            'needsAttention' => Order::query()->where('needs_attention', true)->count(),
            'prizesToSend' => PrizeClaim::query()->where('status', 'open')->count(),
            'instantAwarded' => $won, 'instantRemaining' => max(0, (int) $instant - $won),
            'openDisputes' => Dispute::query()->whereIn('status', ['open', 'evidence_sent'])->count(),
            'referralsToReview' => Referral::query()->where('status', 'review')->count(),
            'busyIps' => Order::query()->whereNotNull('ip')->where('created_at', '>=', now()->subHour())->selectRaw('ip, COUNT(*) n')->groupBy('ip')
                ->having('n', '>', 20)->pluck('n', 'ip')->all(),
        ];
    }

    /** Instant wins by game: plays, winners, prizes paid and still to win, cost against revenue. */
    public function instantWins(Carbon $from, Carbon $to): array
    {
        return Competition::query()->published()->where('type', 'instant')->get()->groupBy('game')->map(function ($comps, $game) use ($from, $to) {
            $refs = $comps->pluck('verity_ref')->filter()->all();
            $lines = OrderLine::query()->whereIn('competition_id', $comps->pluck('id'))->whereHas('order', fn ($q) => $q->where('status', 'paid')->whereBetween('paid_at', [$from, $to]));
            $wins = Winner::query()->whereHas('competition', fn ($q) => $q->whereIn('ref', $refs))->where('type', 'instant')->where('status', 'active')->whereBetween('created_at', [$from, $to]);
            $pool = (int) InstantPrize::query()->whereHas('competition', fn ($q) => $q->whereIn('ref', $refs))->selectRaw('COALESCE(SUM(quantity * value_pence), 0) s')->value('s');
            $paidAll = (int) Winner::query()->whereHas('competition', fn ($q) => $q->whereIn('ref', $refs))->where('type', 'instant')->where('status', 'active')->sum('prize_value_pence');
            $revenue = (int) (clone $lines)->sum('line_pence');
            $cost = (int) (clone $wins)->sum('prize_value_pence');

            return ['game' => $game ?: '—', 'plays' => (int) (clone $lines)->sum('quantity'), 'winners' => (clone $wins)->count(), 'prizesPaid' => $cost,
                'prizesLeft' => max(0, $pool - $paidAll), 'revenue' => $revenue, 'profit' => $revenue - $cost];
        })->values()->all();
    }

    /** Promotions and bundles: free and bonus entries given, their value at ticket price, promo-code discounts. */
    public function promotions(Carbon $from, Carbon $to): array
    {
        $lines = OrderLine::query()->whereHas('order', fn ($q) => $q->whereIn('status', ['paid', 'refunded'])->whereBetween('paid_at', [$from, $to]));
        $value = fn ($q) => (int) $q->selectRaw('COALESCE(SUM((free_quantity + bonus_quantity) * unit_pence), 0) s')->value('s');
        $vouchers = (int) (clone $lines)->where('line_pence', 0)->selectRaw('COALESCE(SUM(quantity * unit_pence), 0) s')->value('s');
        $revenue = (int) $this->paid($from, $to)->sum('total_pence');
        $promoCost = $value(clone $lines) + $vouchers + (int) $this->paid($from, $to)->sum('discount_pence') + (int) $this->paid($from, $to)->sum('paid_bonus_pence');
        $budgetPct = Setting::int('promo_budget_pct');

        return [
            'bundleFreeTickets' => (int) (clone $lines)->sum('free_quantity'), 'bonusTickets' => (int) (clone $lines)->sum('bonus_quantity'),
            'bundleAndBonusValue' => $value(clone $lines), 'voucherValue' => $vouchers,
            'promoCodeDiscounts' => (int) $this->paid($from, $to)->sum('discount_pence'), 'bonusCreditSpent' => (int) $this->paid($from, $to)->sum('paid_bonus_pence'),
            'promoCost' => $promoCost, 'promoPctOfRevenue' => $revenue ? round($promoCost * 100 / $revenue, 1) : null,
            'budgetPct' => $budgetPct ?: null, 'overBudget' => $budgetPct > 0 && $revenue > 0 && $promoCost * 100 / $revenue > $budgetPct,
        ];
    }

    /** Loyalty, memberships and referrals: what each programme gives out and what it brings in. */
    public function programmes(Carbon $from, Carbon $to): array
    {
        $members = Membership::query()->where('status', 'active')->with('tier')->get();

        return [
            'pointsIssued' => (int) LoyaltyEntry::query()->where('points', '>', 0)->whereIn('kind', ['earn', 'bonus', 'adjust'])->whereBetween('created_at', [$from, $to])->sum('points'),
            'pointsRedeemed' => -(int) LoyaltyEntry::query()->where('kind', 'redeem')->whereBetween('created_at', [$from, $to])->sum('points'),
            'pointsOutstanding' => (int) LoyaltyEntry::query()->sum('points'),
            'loyaltyCreditGiven' => (int) LedgerEntry::query()->where('reference', 'like', 'loyalty:%')->whereBetween('created_at', [$from, $to])->sum('amount_pence'),
            'activeMembers' => $members->count(),
            'mrr' => (int) $members->sum(fn ($m) => $m->tier->price_pence),
            'membershipsStarted' => Membership::query()->whereBetween('started_at', [$from, $to])->count(),
            'membershipsEnded' => Membership::query()->whereIn('status', ['cancelled', 'lapsed'])->whereBetween('ended_at', [$from, $to])->count(),
            'membershipPerksCredit' => (int) LedgerEntry::query()->where('reference', 'like', 'membership:%')->whereBetween('created_at', [$from, $to])->sum('amount_pence'),
            'referralsRewarded' => Referral::query()->where('status', 'rewarded')->whereBetween('rewarded_at', [$from, $to])->count(),
            'referralCredit' => (int) ReferralReward::query()->where('type', 'credit')->where('status', 'given')->whereBetween('created_at', [$from, $to])->sum('amount')
                + (int) LedgerEntry::query()->where('reference', 'like', 'referral:%')->whereNull('order_id')->whereBetween('created_at', [$from, $to])->where('reason', 'Refer a friend')->sum('amount_pence'),
            'referralTickets' => (int) ReferralReward::query()->where('type', 'tickets')->where('status', 'given')->whereBetween('created_at', [$from, $to])->sum('amount'),
        ];
    }

    /** Finance: refunds, chargebacks, wallet balances owed to players, and how settlements match our orders. */
    public function finance(Carbon $from, Carbon $to): array
    {
        $bal = fn (string $bucket) => (int) LedgerEntry::query()->where('bucket', $bucket)->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_pence ELSE -amount_pence END), 0) s")->value('s');

        return [
            'refunds' => (int) Order::query()->where('status', 'refunded')->whereBetween('updated_at', [$from, $to])->sum('card_pence'),
            'chargebacks' => Dispute::query()->where('kind', 'chargeback')->whereBetween('created_at', [$from, $to])->count(),
            'chargebacksLost' => (int) Dispute::query()->where('kind', 'chargeback')->whereIn('status', ['lost', 'accepted'])->whereBetween('closed_at', [$from, $to])->sum('amount_pence'),
            'walletCash' => $bal('deposit'), 'walletWinnings' => $bal('winnings'), 'walletBonus' => $bal('bonus'),
            'cardTaken' => (int) Order::query()->whereIn('status', ['paid', 'refunded'])->whereBetween('paid_at', [$from, $to])->sum('card_pence'),
            'settled' => (int) SettlementLine::query()->whereBetween('settled_on', [$from->toDateString(), $to->toDateString()])->sum('amount_pence'),
            'unmatched' => SettlementLine::query()->where('match', '!=', 'matched')->count(),
            'prizesToSend' => PrizeClaim::query()->where('status', 'open')->count(),
        ];
    }

    /** Cohorts: players by the month they joined, and the share who bought in each month after. */
    public function cohorts(int $months = 6): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = now('Europe/London')->startOfMonth()->subMonths($i);
            $ids = User::query()->where('is_admin', false)->whereBetween('created_at', [$start->copy()->utc(), $start->copy()->endOfMonth()->utc()])->pluck('id');
            $row = ['cohort' => $start->format('M Y'), 'players' => $ids->count(), 'months' => []];
            for ($m = 0; $m <= $i; $m++) {
                $ms = $start->copy()->addMonths($m);
                $bought = $ids->isEmpty() ? 0 : Order::query()->whereIn('user_id', $ids)->where('order_type', 'tickets')->where('status', 'paid')
                    ->whereBetween('paid_at', [$ms->copy()->utc(), $ms->copy()->endOfMonth()->utc()])->distinct()->count('user_id');
                $row['months'][] = $ids->count() ? round($bought * 100 / $ids->count()) : null;
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Sales trend: hourly (last 48 hours), daily (last 30 days) or weekly (last 12 weeks). @return array<string,int> */
    public function trend(string $by): array
    {
        [$n, $step, $fmt] = match ($by) {
            'hour' => [48, 'hour', 'D H:00'], 'week' => [12, 'week', 'j M'], default => [30, 'day', 'j M'],
        };
        $start = now('Europe/London')->startOf($step)->sub($step, $n - 1);
        $buckets = [];
        for ($i = 0; $i < $n; $i++) {
            $buckets[$start->copy()->add($step, $i)->format($fmt)] = 0;
        }
        Order::query()->where('order_type', 'tickets')->where('status', 'paid')->where('paid_at', '>=', $start->copy()->utc())->get(['paid_at', 'total_pence'])
            ->each(function ($o) use (&$buckets, $step, $fmt) {
                $k = $o->paid_at->copy()->timezone('Europe/London')->startOf($step)->format($fmt);
                if (isset($buckets[$k])) {
                    $buckets[$k] += (int) $o->total_pence;
                }
            });

        return $buckets;
    }

    /** Order-level rows for exports. */
    public function orderRows(Carbon $from, Carbon $to): array
    {
        $rows = [['Order', 'Type', 'Status', 'Paid (UK)', 'Player email', 'Total £', 'Card £', 'Wallet cash £', 'Bonus £', 'Winnings £', 'Discount £', 'Promo code', 'Channel', 'Tickets']];
        Order::query()->with('user:id,email', 'lines')->whereIn('status', ['paid', 'refunded'])->whereBetween('paid_at', [$from, $to])->orderBy('paid_at')
            ->chunk(500, function ($orders) use (&$rows) {
                foreach ($orders as $o) {
                    $rows[] = [$o->number, $o->order_type, $o->status, $o->paid_at?->timezone('Europe/London')->format('Y-m-d H:i'), $o->user?->email,
                        $o->total_pence / 100, $o->card_pence / 100, $o->paid_deposit_pence / 100, $o->paid_bonus_pence / 100, $o->paid_winnings_pence / 100,
                        $o->discount_pence / 100, $o->promo_code, $o->channel, (int) $o->lines->sum('quantity')];
                }
            });

        return $rows;
    }
}

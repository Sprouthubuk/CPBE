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
        // Amendment 3: VAT shown on its own (the rate, if any, is in Settings; prices include it).
        $vatPct = Setting::int('vat_pct');
        $vat = $vatPct > 0 ? intdiv(($real + $membershipRevenue - $refunds) * $vatPct, 100 + $vatPct) : 0;
        $costs = [
            'Instant-win prizes' => $instantPrizes,
            'Draw prizes' => $drawPrizes,
            'Payment fees (estimated)' => $fees,
            'Promotions (bonus credit used)' => $bonusUsed,
        ];
        if ($vatPct > 0) {
            $costs['VAT ('.$vatPct.'%)'] = $vat;
        }
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
            'vat' => $vat, 'vatPct' => $vatPct,
            // Amendment 3: what each entry actually brought in, and costs the figures don't include yet.
            'effectivePerTicket' => ($all = $paidEntries + $bundleFree + $bonusEntries + $promoEntries) > 0 ? intdiv($net, $all) : null,
            'missingCosts' => $this->missingCosts($from, $to, $manual),
            'asOf' => now()->toIso8601String(),
        ];
    }

    /**
     * Amendment 3: costs that aren't recorded yet, so the profit shown is known to be incomplete: ended draws with no
     * prize cost, sales channels with revenue but no marketing spend entered, and no running costs this period.
     *
     * @return list<string>
     */
    public function missingCosts(Carbon $from, Carbon $to, ?Collection $manual = null): array
    {
        $out = [];
        foreach (Competition::query()->published()->where('type', 'draw')->whereNull('prize_cost_pence')->where('closes_at', '<=', now())->whereBetween('closes_at', [$from, $to])->pluck('title') as $t) {
            $out[] = "{$t}: no prize cost entered (using the prize value).";
        }
        $manual ??= CostEntry::query()->whereDate('date', '>=', $from->copy()->timezone('Europe/London')->toDateString())->whereDate('date', '<=', $to->copy()->timezone('Europe/London')->toDateString())->get();
        $spendChannels = $manual->where('category', 'marketing')->pluck('channel')->filter()->unique()->all();
        $paidChannels = $this->paid($from, $to)->whereNotNull('channel')->where('channel', '!=', 'direct')->distinct()->pluck('channel')->all();
        foreach (array_diff($paidChannels, $spendChannels) as $ch) {
            $out[] = "Sales came from {$ch} but no {$ch} spend is entered for the period.";
        }
        if ($manual->where('category', 'operating')->isEmpty()) {
            $out[] = 'No running costs entered for the period.';
        }

        return array_values($out);
    }

    /**
     * Amendment 2: daily totals for the accounts, one row per UK day: sales, refunds, site credit used, wallet
     * balances at the end of the day and payment provider fees. A plain CSV / Excel layout that accounting and
     * bookkeeping software imports (or the journal() layout below).
     *
     * @return list<array<string, int|string>>
     */
    public function dailyTotals(Carbon $from, Carbon $to): array
    {
        $tz = 'Europe/London';
        $days = [];
        for ($d = $from->copy()->timezone($tz)->startOfDay(); $d->lte($to->copy()->timezone($tz)); $d->addDay()) {
            $days[$d->toDateString()] = ['start' => $d->copy()->utc(), 'end' => $d->copy()->endOfDay()->utc()];
        }
        // Wallet balances: the opening balance, then each day's movements.
        $bal = fn (Carbon $before) => LedgerEntry::query()->where('created_at', '<', $before)->selectRaw("bucket, COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_pence ELSE -amount_pence END), 0) s")->groupBy('bucket')->pluck('s', 'bucket');
        $running = $bal($from)->map(fn ($v) => (int) $v)->all();
        $rows = [];
        foreach ($days as $date => $w) {
            $o = Order::query()->whereIn('status', ['paid', 'refunded'])->whereBetween('paid_at', [$w['start'], $w['end']]);
            $tickets = (clone $o)->whereIn('order_type', ['tickets', 'membership']);
            $card = (int) (clone $o)->sum('card_pence');
            $payments = (clone $o)->where('card_pence', '>', 0)->count();
            $moves = LedgerEntry::query()->whereBetween('created_at', [$w['start'], $w['end']])
                ->selectRaw("bucket, COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_pence ELSE -amount_pence END), 0) s")->groupBy('bucket')->pluck('s', 'bucket');
            foreach ($moves as $b => $v) {
                $running[$b] = ($running[$b] ?? 0) + (int) $v;
            }
            $rows[] = [
                'Date' => $date,
                'Sales (entries and memberships)' => (int) (clone $tickets)->sum('total_pence'),
                'Paid by card' => $card,
                'Paid from wallet cash and winnings' => (int) (clone $tickets)->selectRaw('COALESCE(SUM(paid_deposit_pence + paid_winnings_pence), 0) s')->value('s'),
                'Site credit used (bonus)' => (int) (clone $tickets)->sum('paid_bonus_pence'),
                'Promo code discounts' => (int) (clone $tickets)->sum('discount_pence'),
                'Wallet top-ups' => (int) (clone $o)->where('order_type', 'deposit')->sum('total_pence'),
                'Refunds' => (int) Order::query()->where('status', 'refunded')->whereBetween('updated_at', [$w['start'], $w['end']])->sum('card_pence'),
                'Payment fees (estimated)' => self::cardFee($card, $payments),
                'Withdrawals paid' => (int) \App\Models\Withdrawal::query()->where('status', 'paid')->whereBetween('decided_at', [$w['start'], $w['end']])->sum('amount_pence'),
                'Wallet cash at end of day' => (int) ($running['deposit'] ?? 0),
                'Wallet winnings at end of day' => (int) ($running['winnings'] ?? 0),
                'Site credit at end of day' => (int) ($running['bonus'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * The same days as journal lines (Date, Reference, Description, Account, Debit, Credit, in pounds), for software
     * that imports manual journals. Account names are generic: map them to the chart of accounts once.
     *
     * @return list<list<string|float>>
     */
    public function journal(Carbon $from, Carbon $to): array
    {
        $out = [['Date', 'Reference', 'Description', 'Account', 'Debit', 'Credit']];
        $p = fn (int $pence) => round($pence / 100, 2);
        foreach ($this->dailyTotals($from, $to) as $d) {
            $ref = 'DAY-'.str_replace('-', '', $d['Date']);
            $lines = [
                ['Card clearing', $d['Paid by card'], 0], ['Player wallets (cash and winnings)', $d['Paid from wallet cash and winnings'], 0],
                ['Promotional credit', $d['Site credit used (bonus)'], 0], ['Competition sales', 0, $d['Sales (entries and memberships)']],
                ['Card clearing', $d['Wallet top-ups'], 0], ['Player wallets (cash and winnings)', 0, $d['Wallet top-ups']],
                ['Refunds', $d['Refunds'], 0], ['Card clearing', 0, $d['Refunds']],
                ['Payment fees', $d['Payment fees (estimated)'], 0], ['Card clearing', 0, $d['Payment fees (estimated)']],
            ];
            foreach ($lines as [$acc, $dr, $cr]) {
                if ($dr > 0 || $cr > 0) {
                    $out[] = [$d['Date'], $ref, 'Daily totals '.$d['Date'], $acc, $p($dr), $p($cr)];
                }
            }
        }

        return $out;
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
                // Amendment 3: what's left, what each entry really brought in, and profit at each sell-through.
                'left' => $left,
                'effectivePerTicket' => $sold > 0 ? intdiv($revenue, $sold) : null,
                'scenarios' => collect([30, 40, 50, 60, 70, 80, 90, 100])->mapWithKeys(function ($pct) use ($c, $sold, $revenue, $prizeCost) {
                    $tickets = (int) floor($c->max_tickets * $pct / 100);
                    $perTicket = $sold > 0 ? $revenue / $sold : (float) $c->price_pence;
                    $rev = (int) round($perTicket * $tickets);
                    $fees = self::cardFee($rev, (int) ceil($rev / 1000));

                    return [$pct => $rev - $prizeCost - $fees];
                })->all(),
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
        // Amendment 3: checkout conversion (paid / started) and profit by channel, after an equal share of prize cost and fees.
        $started = Order::query()->where('order_type', 'tickets')->whereBetween('created_at', [$from, $to])->selectRaw("COALESCE(channel, 'direct') ch, COUNT(*) n, SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) p")
            ->groupBy('ch')->get()->keyBy('ch');
        $co = $this->company($from, $to);
        $costShare = $co['netRevenue'] > 0 ? ($co['costs']['Instant-win prizes'] + $co['costs']['Draw prizes'] + $co['costs']['Payment fees (estimated)']) / $co['netRevenue'] : 0;
        $rows = $channels->map(fn ($ch) => [
            'conversionPct' => isset($started[$ch]) && $started[$ch]->n > 0 ? round($started[$ch]->p * 100 / $started[$ch]->n, 1) : null,
            'profit' => (int) round(($revenue[$ch] ?? 0) * (1 - $costShare)) - ($spend[$ch] ?? 0),
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
            // Amendment 3: complaints, system errors and urgent tasks.
            'complaints' => \App\Models\ContactMessage::query()->whereNull('handled_at')->where('topic', 'like', '%omplain%')->count(),
            'unansweredMessages' => \App\Models\ContactMessage::query()->whereNull('handled_at')->count(),
            'systemErrors' => (\Illuminate\Support\Facades\Schema::hasTable('failed_jobs') ? (int) \Illuminate\Support\Facades\DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0)
                + Order::query()->where('needs_attention', true)->where('updated_at', '>=', now()->subDay())->count(),
            'urgent' => $this->urgent(),
        ];
    }

    /**
     * Amendment 3: what needs a person now, each with where to deal with it.
     *
     * @return list<array{label: string, count: int, url: string}>
     */
    public function urgent(): array
    {
        $rows = [
            ['Orders needing attention', Order::query()->where('needs_attention', true)->where('status', 'paid')->count(), '/admin/orders'],
            ['Player protection flags', \App\Models\HarmFlag::query()->whereNull('reviewed_at')->count(), '/admin/player-protection'],
            ['ID checks waiting', \App\Models\IdCheck::query()->where('status', 'pending')->count(), '/admin/id-checks'],
            ['Prizes to send', PrizeClaim::query()->where('status', 'open')->count(), '/admin/prize-claims'],
            ['Disputes due within 3 days', Dispute::query()->whereIn('status', ['open', 'evidence_sent'])->whereNotNull('respond_by')->where('respond_by', '<=', now()->addDays(3))->count(), '/admin/disputes'],
            ['Withdrawals to pay', \App\Models\Withdrawal::query()->where('status', 'requested')->count(), '/admin/withdrawals'],
            ['Unanswered messages', \App\Models\ContactMessage::query()->whereNull('handled_at')->count(), '/admin/contact-messages'],
        ];

        return array_values(array_map(fn ($r) => ['label' => $r[0], 'count' => $r[1], 'url' => $r[2]], array_filter($rows, fn ($r) => $r[1] > 0)));
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

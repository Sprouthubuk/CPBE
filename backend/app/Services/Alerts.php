<?php

namespace App\Services;

use App\Models\AlertRule;
use App\Models\CostEntry;
use App\Models\Order;
use App\Notifications\AdminAlert;

/** Configurable alerts (SOW 2.14). Checked hourly; each rule emails the admins at most once a day. */
class Alerts
{
    public function __construct(private Reports $reports) {}

    /** @return list<string> the rules that fired */
    public function check(): array
    {
        $fired = [];
        foreach (AlertRule::query()->where('active', true)->get() as $rule) {
            if ($rule->last_fired_at && $rule->last_fired_at->gt(now()->subDay())) {
                continue;
            }
            $lines = $this->evaluate($rule);
            if ($lines) {
                AdminAlert::send(ucfirst(str_replace('_', ' ', $rule->kind)), $lines, '/admin/business');
                $rule->forceFill(['last_fired_at' => now()])->save();
                $fired[] = $rule->kind;
            }
        }

        return $fired;
    }

    /** @return list<string> what to say, or [] when all is well */
    public function evaluate(AlertRule $rule): array
    {
        $t = (int) $rule->threshold;
        $weekAgo = now()->subDays(7);
        $revenue = fn () => (int) Order::query()->where('order_type', 'tickets')->where('status', 'paid')->where('paid_at', '>=', $weekAgo)->sum('total_pence');

        return match ($rule->kind) {
            'break_even_risk' => $this->reports->competitions()->filter(fn ($c) => $c['atRisk'] && $c['closesAt']->isFuture() && now()->diffInHours($c['closesAt']) <= $t)
                ->map(fn ($c) => "{$c['title']}: forecast {$c['forecastSold']} tickets, break-even is {$c['breakEvenTickets']}.")->values()->all(),
            'underperforming' => $this->reports->competitions()->filter(function ($c) use ($t) {
                $comp = \App\Models\Competition::query()->find($c['id']);
                $start = $comp?->opens_at ?? $comp?->published_at;
                if (! $start || ! $c['closesAt']->isFuture()) {
                    return false;
                }
                $elapsed = $start->diffInSeconds(now()) / max(1, $start->diffInSeconds($c['closesAt']));

                return $elapsed >= 0.5 && $c['soldPct'] < $t;
            })->map(fn ($c) => "{$c['title']}: {$c['soldPct']}% sold with more than half its time gone.")->values()->all(),
            'marketing_cost_high' => ($rev = $revenue()) > 0 && ($spend = (int) CostEntry::query()->where('category', 'marketing')->whereDate('date', '>=', $weekAgo->toDateString())->sum('amount_pence')) * 100 > $t * $rev
                ? ['Marketing spend in the last 7 days is '.round($spend * 100 / $rev, 1)."% of revenue (alert at {$t}%)."] : [],
            'promo_budget' => ($p = $this->reports->promotions($weekAgo, now()))['promoPctOfRevenue'] !== null && $p['promoPctOfRevenue'] > $t
                ? ["Promotions cost {$p['promoPctOfRevenue']}% of revenue in the last 7 days (alert at {$t}%)."] : [],
            'unusual_activity' => collect(Order::query()->whereNotNull('ip')->where('created_at', '>=', now()->subHour())->selectRaw('ip, COUNT(*) n')->groupBy('ip')
                ->having('n', '>', $t)->pluck('n', 'ip'))->map(fn ($n, $ip) => "{$n} orders from {$ip} in the last hour.")->values()->all(),
            'sales_drop' => (function () use ($t) {
                $today = (int) Order::query()->where('order_type', 'tickets')->where('status', 'paid')->where('paid_at', '>=', now('Europe/London')->startOfDay()->utc())->sum('total_pence');
                $lastWeek = (int) Order::query()->where('order_type', 'tickets')->where('status', 'paid')
                    ->whereBetween('paid_at', [now('Europe/London')->startOfDay()->subWeek()->utc(), now()->subWeek()])->sum('total_pence');

                return $lastWeek > 0 && ($lastWeek - $today) * 100 > $t * $lastWeek
                    ? ['Sales so far today are '.round(($lastWeek - $today) * 100 / $lastWeek)."% down on the same time last week."] : [];
            })(),
            default => [],
        };
    }
}

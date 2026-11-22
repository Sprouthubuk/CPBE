<?php

namespace App\Services;

use App\Models\Competition;
use App\Models\Experiment;
use App\Models\ExperimentAssignment;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Notifications\AbandonedCheckout;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Checkout and conversion tools (SOW 2.18): recommendations, upsells, one-click repeat purchase, abandoned
 * checkout recovery and A/B tests, plus the revenue each one brings in (order_lines.via).
 */
class Conversion
{
    public const VIA = ['upsell', 'repeat', 'recovery', 'recommendation'];

    public function __construct(private CompetitionState $state, private PlayCheck $check) {}

    /**
     * Live competitions for this player, best match first: the kind they play (instant or draw), the same
     * categories (tags), ones they haven't entered, then closing soonest. Signed out: featured, then closing soonest.
     *
     * @param  list<string>  $exclude  competition ids already in the basket
     * @return Collection<int, Competition>
     */
    public function recommend(?User $user, array $exclude = [], int $limit = 4): Collection
    {
        $live = Competition::query()->published()->with(['prizes', 'bundleTiers'])->where('closes_at', '>', now())->whereNotIn('id', $exclude)->get()
            ->filter(fn (Competition $c) => $this->state->status($c) === 'live');
        $types = collect();
        $tags = collect();
        $entered = collect();
        if ($user) {
            $lines = OrderLine::query()->whereHas('order', fn ($q) => $q->where('user_id', $user->id)->where('status', 'paid'))
                ->with('competition:id,type,tags')->latest('id')->limit(200)->get();
            $entered = $lines->pluck('competition_id')->unique();
            $types = $lines->pluck('competition.type')->filter()->countBy();
            $tags = $lines->flatMap(fn ($l) => (array) ($l->competition?->tags ?? []))->countBy();
        }

        return $live->map(function (Competition $c) use ($types, $tags, $entered) {
            $score = ($types[$c->type] ?? 0) > 0 ? 3 : 0;
            foreach ((array) $c->tags as $t) {
                $score += ($tags[$t] ?? 0) > 0 ? 2 : 0;
            }
            $score += $entered->contains($c->id) ? 0 : 1;
            $score += $c->featured ? 1 : 0;

            return [$c, $score];
        })->sort(fn ($a, $b) => [$b[1], $a[0]->closes_at->timestamp] <=> [$a[1], $b[0]->closes_at->timestamp])
            ->take($limit)->map(fn ($a) => $a[0])->values();
    }

    /**
     * One-click repeat purchase: the lines of an earlier order that can still be bought, as basket lines.
     *
     * @return array{lines: list<array<string, mixed>>, skipped: list<string>}
     */
    public function repeat(Order $order, string $via = 'repeat'): array
    {
        $lines = [];
        $skipped = [];
        foreach ($order->lines()->with('competition')->get() as $l) {
            $c = $l->competition;
            if (! $c || $this->state->status($c) !== 'live' || $l->line_pence === 0) {
                $skipped[] = $c?->title ?? 'A competition';

                continue;
            }
            $lines[] = array_filter(['competitionId' => $c->id, 'quantity' => $l->bundle_tier_id ? 0 : (int) $l->paid_quantity,
                'bundleId' => $l->bundle_tier_id, 'via' => $via], fn ($v) => $v !== null);
        }

        return ['lines' => $lines, 'skipped' => array_values(array_unique($skipped))];
    }

    /**
     * Every 15 minutes: one reminder for a ticket checkout left unpaid 1 to 24 hours ago, only to players who
     * opted in to marketing emails, who can play, and who haven't paid for an order since. Returns how many.
     */
    public function recoverAbandoned(): int
    {
        $sent = 0;
        $orders = Order::query()->with('user')->where('order_type', 'tickets')->where('total_pence', '>', 0)
            ->whereIn('status', ['created', 'pending_payment', 'expired', 'cancelled', 'failed'])
            ->whereNull('recovery_sent_at')->whereBetween('created_at', [now()->subDay(), now()->subHour()])->orderBy('created_at')->limit(200)->get();
        foreach ($orders->unique('user_id') as $o) {
            $u = $o->user;
            $paidSince = Order::query()->where('user_id', $u->id)->where('status', 'paid')->where('order_type', 'tickets')->where('created_at', '>=', $o->created_at)->exists();
            $recent = Order::query()->where('user_id', $u->id)->where('recovery_sent_at', '>=', now()->subDay())->exists();
            Order::query()->where('user_id', $u->id)->whereIn('id', $orders->where('user_id', $u->id)->pluck('id'))->update(['recovery_sent_at' => now()]);
            if ($paidSince || $recent || ! $u->marketing_email || $this->check->block($u) || ! $this->repeat($o, 'recovery')['lines']) {
                continue;
            }
            try {
                $u->notify(new AbandonedCheckout($o));
                app(MarketingSync::class)->event($u, 'Abandoned Checkout', ['order' => $o->number, 'value' => $o->total_pence / 100]);
                $sent++;
            } catch (\Throwable) {
                Log::warning('Checkout reminder not sent', ['order' => $o->number]);
            }
        }

        return $sent;
    }

    /** A/B tests: the visitor's variant for each running test, assigning on first sight. @return array<string, array{variant: string, text: ?string}> */
    public function experiments(string $subject): array
    {
        $out = [];
        foreach (Experiment::query()->where('active', true)->get() as $e) {
            $a = ExperimentAssignment::query()->firstOrCreate(['experiment_id' => $e->id, 'subject' => $subject], ['variant' => $e->pick($subject)['key']]);
            $v = collect($e->variants)->firstWhere('key', $a->variant) ?? $e->pick($subject);
            $out[$e->key] = ['variant' => $a->variant, 'text' => $v['text'] ?? null];
        }

        return $out;
    }

    public function seen(string $subject, string $key): void
    {
        $e = Experiment::query()->where('key', $key)->where('active', true)->first();
        if ($e) {
            ExperimentAssignment::query()->where('experiment_id', $e->id)->where('subject', $subject)->whereNull('seen_at')->update(['seen_at' => now()]);
        }
    }

    /** Orders::markPaid: the order converts the tests its player (or consenting visitor) saw. */
    public function orderPaid(Order $order): void
    {
        $subjects = array_filter(['u:'.$order->user_id, $order->visitor_id ? 'v:'.$order->visitor_id : null]);
        $real = (int) $order->card_pence + (int) $order->paid_deposit_pence + (int) $order->paid_winnings_pence;
        ExperimentAssignment::query()->whereIn('subject', $subjects)->whereNotNull('seen_at')->where('seen_at', '<=', $order->paid_at ?? now())
            ->get()->each(function (ExperimentAssignment $a) use ($real) {
                $a->forceFill(['converted_at' => $a->converted_at ?? now(), 'revenue_pence' => $a->revenue_pence + $real])->save();
            });
    }

    /** Revenue and estimated profit each tool brought in. @return list<array{via: string, orders: int, revenue: int}> */
    public function toolReport(\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): array
    {
        return OrderLine::query()->whereNotNull('via')->whereHas('order', fn ($q) => $q->where('status', 'paid')->whereBetween('paid_at', [$from, $to]))
            ->get(['order_id', 'via', 'line_pence'])->groupBy('via')
            ->map(fn ($g, $via) => ['via' => $via, 'orders' => $g->pluck('order_id')->unique()->count(), 'revenue' => (int) $g->sum('line_pence')])->values()->all();
    }
}

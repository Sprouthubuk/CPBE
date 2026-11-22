<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer segments (SOW 2.14) for the email and SMS platforms (SOW 3.3). Only players who opted in to marketing
 * are ever included, and never anyone self-excluded, on a break, on hold or closed.
 */
class Segments
{
    public const SEGMENTS = [
        'all' => 'Everyone opted in',
        'new' => 'Joined in the last 7 days',
        'never_bought' => 'Never bought',
        'active' => 'Bought in the last 30 days',
        'inactive' => 'Bought before, not in the last 30 days',
        'high_value' => 'Top 10% by lifetime spend',
        'members' => 'Current members',
        'abandoned' => 'Left a checkout unpaid in the last 24 hours',
    ];

    /** The players in a segment who may be marketed to. */
    public function query(string $segment): Builder
    {
        $now = now();
        $q = User::query()->where('is_admin', false)->where('is_staff', false)->whereNull('closed_at')->whereNull('on_hold_at')
            ->where(fn ($w) => $w->where('marketing_email', true)->orWhere('marketing_sms', true))
            ->where(fn ($w) => $w->whereNull('self_excluded_until')->orWhere('self_excluded_until', '<', $now))
            ->where(fn ($w) => $w->whereNull('cool_off_until')->orWhere('cool_off_until', '<', $now));
        $paid = fn () => Order::query()->select('user_id')->where('order_type', 'tickets')->where('status', 'paid')->where('total_pence', '>', 0);

        return match ($segment) {
            'new' => $q->where('created_at', '>=', $now->copy()->subDays(7)),
            'never_bought' => $q->whereNotIn('id', $paid()),
            'active' => $q->whereIn('id', $paid()->where('paid_at', '>=', $now->copy()->subDays(30))),
            'inactive' => $q->whereIn('id', $paid())->whereNotIn('id', $paid()->where('paid_at', '>=', $now->copy()->subDays(30))),
            'high_value' => $q->whereIn('id', $this->topSpenders()),
            'members' => $q->whereIn('id', Membership::query()->select('user_id')->where('status', 'active')),
            'abandoned' => $q->whereIn('id', Order::query()->select('user_id')->where('order_type', 'tickets')->whereIn('status', ['created', 'pending_payment', 'expired', 'cancelled'])
                ->where('created_at', '>=', $now->copy()->subDay()))->whereNotIn('id', $paid()->where('paid_at', '>=', $now->copy()->subDay())),
            default => $q,
        };
    }

    /** @return list<int> */
    private function topSpenders(): array
    {
        $spend = Order::query()->where('order_type', 'tickets')->where('status', 'paid')->selectRaw('user_id, SUM(card_pence + paid_deposit_pence + paid_winnings_pence) s')
            ->groupBy('user_id')->orderByDesc('s')->pluck('s', 'user_id');
        $take = max(1, (int) ceil($spend->count() / 10));

        return $spend->keys()->take($take)->all();
    }

    /** Rows for a CSV the Client can upload to its email or SMS platform. */
    public function rows(string $segment): array
    {
        $segment = array_key_exists($segment, self::SEGMENTS) ? $segment : 'all';
        $rows = [['Email', 'First name', 'Last name', 'Mobile (SMS opt-in only)', 'Email opt-in', 'SMS opt-in', 'Segment', 'Joined']];
        $this->query($segment)->orderBy('id')->chunk(500, function ($users) use (&$rows, $segment) {
            foreach ($users as $u) {
                $rows[] = [$u->marketing_email ? $u->email : '', $u->first_name, $u->last_name, $u->marketing_sms ? $u->phone : '', $u->marketing_email ? 'yes' : 'no',
                    $u->marketing_sms ? 'yes' : 'no', self::SEGMENTS[$segment], $u->created_at?->timezone('Europe/London')->format('Y-m-d')];
            }
        });

        return $rows;
    }
}

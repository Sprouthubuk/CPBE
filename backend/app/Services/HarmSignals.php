<?php

namespace App\Services;

use App\Models\HarmFlag;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Voluntary Code 1.6 / 1.7 (amendment 11): watch for signs of harm from account opening and put them in front of a
 * person (Admin → Player Protection), who decides a proportionate step: no action, contact the player, or put the
 * account on hold. Each indicator raises one flag per period, so a busy player isn't flagged over and over.
 */
final class HarmSignals
{
    public static function raise(User $u, string $indicator, string $detail, string $period): void
    {
        $key = "{$indicator}:{$u->id}:{$period}";
        $write = function () use ($u, $indicator, $detail, $key) {
            $flag = HarmFlag::query()->firstOrCreate(['key' => $key], ['user_id' => $u->id, 'indicator' => $indicator, 'detail' => mb_substr($detail, 0, 300)]);
            if ($flag->wasRecentlyCreated) {
                try {
                    \App\Notifications\AdminAlert::send('Player protection: '.(HarmFlag::INDICATORS[$indicator] ?? $indicator), [$detail], '/admin/player-protection');
                } catch (\Throwable) {
                }
            }
        };
        // A refusal rolls its transaction back, so the flag is written once the request is over, outside it.
        DB::transactionLevel() > 0 ? app()->terminating($write) : $write();
    }

    /** The periodic checks (every hour). Returns how many new flags were raised. */
    public function scan(): int
    {
        $before = HarmFlag::query()->count();
        $spend = (int) Setting::get('harm_spend_30d_pence');
        $topUps = (int) Setting::get('harm_top_ups_24h');
        $cards = (int) Setting::get('harm_cards_30d');
        $week = now('Europe/London')->format('o-\WW');

        foreach (Order::query()->where('status', 'paid')->where('paid_at', '>=', now()->subDays(30))->where('card_pence', '>', 0)
            ->selectRaw('user_id, SUM(card_pence) s')->groupBy('user_id')->havingRaw('SUM(card_pence) > ?', [$spend])->get() as $r) {
            self::flagNow($r->user_id, 'spend_30d', 'Paid in £'.number_format($r->s / 100, 2).' in the last 30 days.', $week);
        }
        foreach (Order::query()->where('status', 'paid')->where('order_type', 'deposit')->where('paid_at', '>=', now()->subDay())
            ->selectRaw('user_id, COUNT(*) n')->groupBy('user_id')->havingRaw('COUNT(*) >= ?', [$topUps])->get() as $r) {
            self::flagNow($r->user_id, 'top_ups_24h', $r->n.' top-ups in the last 24 hours.', now('Europe/London')->toDateString());
        }
        foreach (Order::query()->where('status', 'paid')->whereNotNull('card_hash')->where('paid_at', '>=', now()->subDays(30))
            ->selectRaw('user_id, COUNT(DISTINCT card_hash) n')->groupBy('user_id')->havingRaw('COUNT(DISTINCT card_hash) >= ?', [$cards])->get() as $r) {
            self::flagNow($r->user_id, 'cards_30d', $r->n.' different cards in the last 30 days.', now('Europe/London')->format('Y-m'));
        }
        // Back from a break and paying in within a day of it ending.
        foreach (User::query()->whereNotNull('cool_off_until')->whereBetween('cool_off_until', [now()->subDays(2), now()])->get() as $u) {
            if (Order::query()->where('user_id', $u->id)->where('status', 'paid')->where('card_pence', '>', 0)->where('paid_at', '>=', $u->cool_off_until)->where('paid_at', '<=', $u->cool_off_until->copy()->addDay())->exists()) {
                self::flagNow($u->id, 'returned_from_break', 'Paid in within a day of a break ending ('.$u->cool_off_until->timezone('Europe/London')->format('j M H:i').').', $u->cool_off_until->toDateString());
            }
        }

        return HarmFlag::query()->count() - $before;
    }

    private static function flagNow(int $userId, string $indicator, string $detail, string $period): void
    {
        HarmFlag::query()->firstOrCreate(['key' => "{$indicator}:{$userId}:{$period}"], ['user_id' => $userId, 'indicator' => $indicator, 'detail' => $detail]);
    }
}

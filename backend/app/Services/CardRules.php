<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Voluntary Code 1.3 (amendment 11): no more than £250 a month per player in credit card payments, and none at
 * all for instant win draws. Decided before the payment page opens: when a credit card isn't allowed the payment
 * page accepts debit cards only. Wallet top-ups and memberships are debit only too, so wallet money can never be
 * credit-card money spent on an instant win. If the gateway still reports a credit card where one wasn't allowed,
 * nothing is issued and the order is flagged for a person to refund.
 */
final class CardRules
{
    public const DEFAULT_CAP_PENCE = 25000;

    public static function monthlyCapPence(): int
    {
        return min(self::DEFAULT_CAP_PENCE, (int) (Setting::get('credit_card_monthly_cap_pence') ?? self::DEFAULT_CAP_PENCE));
    }

    /** Credit card payments this player made this calendar month (UK time). */
    public static function creditUsedThisMonth(int $userId): int
    {
        $start = Carbon::now('Europe/London')->startOfMonth()->utc();

        return (int) Order::query()->where('user_id', $userId)->where('status', 'paid')->where('card_type', 'credit')
            ->where('paid_at', '>=', $start)->sum('card_pence');
    }

    /** Whether this order's card part may be paid by credit card. */
    public static function creditAllowed(Order $order): bool
    {
        if ($order->card_pence <= 0 || $order->order_type === 'membership' || $order->isDeposit()) {
            return false;
        }
        if ($order->lines()->whereHas('competition', fn ($query) => $query->where('type', 'instant'))->exists()) {
            return false;
        }

        return self::creditUsedThisMonth($order->user_id) + $order->card_pence <= self::monthlyCapPence();
    }

    /** What the player is told on the checkout when only debit cards are accepted. */
    public static function reason(Order $order): ?string
    {
        if ($order->credit_allowed || $order->card_pence <= 0) {
            return null;
        }

        return $order->order_type === 'membership' || $order->isDeposit()
            ? 'Top-ups and memberships are paid by debit card.'
            : 'Credit cards can’t be used for instant wins, or for more than '.'£'.number_format(self::monthlyCapPence() / 100, 0).' a month on draws, so this payment is by debit card.';
    }
}

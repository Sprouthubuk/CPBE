<?php

namespace App\Services;

use App\Exceptions\RefusedException;
use App\Models\AdminAction;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Monthly memberships (SOW 2.12). Joining, upgrading, downgrading and cancelling; renewals from the wallet
 * or the card the first payment was made with; perks each paid month. Money moves only through Orders
 * (membershipPayment, chargeRenewal); this service decides what to charge and what a paid order unlocks.
 *
 * A failed renewal pauses the perks (past_due) and is retried RETRY_DAYS later, up to MAX_ATTEMPTS times,
 * then the membership lapses. Self-exclusion, a break or a closed account end it at once.
 */
class Memberships
{
    /** Days between renewals sent to the gateway (a calendar month is used for the period itself). */
    public const PERIOD_DAYS = 30;

    public const RETRY_DAYS = 3;

    public const MAX_ATTEMPTS = 3;

    public function __construct(private Orders $orders, private PlayCheck $check, private Wallet $wallet) {}

    /** The longest early access any open tier gives: how much sooner competitions go live in Verity. */
    public static function maxEarlyAccessHours(): int
    {
        return (int) MembershipTier::query()->where('active', true)->max('early_access_hours');
    }

    /** The player's membership that is running (active or waiting on a renewal), if any. */
    public function current(User|int $user): ?Membership
    {
        $id = $user instanceof User ? $user->id : $user;

        return Membership::query()->with('tier', 'pendingTier')->where('user_id', $id)->whereIn('status', Membership::CURRENT)->latest('id')->first();
    }

    /** The tier whose perks apply right now (paid up), if any. */
    public function perkTier(User|int $user): ?MembershipTier
    {
        $m = $this->current($user);

        return $m && $m->perksOn() ? $m->tier : null;
    }

    /** Why this player can't join $tier, or null. */
    public function ineligible(User $user, MembershipTier $tier): ?string
    {
        if (! $tier->active) {
            return 'This membership isn’t open to join right now.';
        }
        if ($tier->max_members !== null && $tier->memberships()->whereIn('status', Membership::CURRENT)->count() >= $tier->max_members) {
            return 'This membership is full right now.';
        }

        return match ($tier->eligibility) {
            'id_verified' => $user->id_verified_at ? null : 'This membership is for ID-checked players. Verify your ID first.',
            'min_spend' => $this->lifetimeSpend($user) >= $tier->min_spend_pence ? null
                : 'This membership opens once you’ve spent £'.number_format($tier->min_spend_pence / 100, 2).' with us.',
            default => null,
        };
    }

    public function lifetimeSpend(User $user): int
    {
        return (int) Order::query()->where('user_id', $user->id)->where('order_type', 'tickets')->where('status', 'paid')
            ->selectRaw('COALESCE(SUM(card_pence + paid_deposit_pence + paid_winnings_pence), 0) as s')->value('s');
    }

    /** Join a tier. Wallet: paid at once. Card: the order waits for the payment page. */
    public function join(User $user, MembershipTier $tier, string $payWith): Order
    {
        $this->assertCanPay($user);
        if ($this->current($user)) {
            throw new RefusedException('You already have a membership. Change it from your account.', 'membership_exists');
        }
        if ($why = $this->ineligible($user, $tier)) {
            throw new RefusedException($why, 'membership_ineligible');
        }
        $this->assertWallet($user, $payWith, $tier->price_pence);
        // An earlier unpaid attempt is replaced.
        Membership::query()->where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'cancelled', 'ended_at' => now(), 'end_reason' => 'never paid']);
        $m = Membership::query()->create(['user_id' => $user->id, 'membership_tier_id' => $tier->id, 'status' => 'pending', 'pay_with' => $payWith]);

        return $this->orders->membershipPayment($user, $m, $tier, $tier->price_pence, 'join', $payWith);
    }

    /**
     * Move to a dearer tier now: the difference for the rest of this month is charged (wallet, the stored card,
     * or the payment page), and the new tier applies once it's paid. Its full price applies from the next renewal.
     */
    public function upgrade(User $user, MembershipTier $tier): ?Order
    {
        $this->assertCanPay($user);
        $m = $this->current($user);
        if (! $m || $m->status !== 'active') {
            throw new RefusedException('Your membership needs to be paid up to change it.', 'membership_not_active');
        }
        if ($tier->price_pence <= $m->tier->price_pence || $tier->id === $m->membership_tier_id) {
            throw new RefusedException('Choose a tier that costs more to upgrade.', 'membership_not_upgrade');
        }
        if ($why = $this->ineligible($user, $tier)) {
            throw new RefusedException($why, 'membership_ineligible');
        }
        $pence = $this->prorated($m, $tier->price_pence - $m->tier->price_pence);
        if ($pence < 1) {
            $this->switchTier($m, $tier);

            return null;
        }
        if ($m->pay_with === 'wallet' && $this->wallet->balances($user)['deposit'] + $this->wallet->balances($user)['winnings'] >= $pence) {
            return $this->orders->membershipPayment($user, $m, $tier, $pence, 'upgrade', 'wallet');
        }
        if ($m->gateway_parent_ref) {
            $order = $this->orders->membershipPayment($user, $m, $tier, $pence, 'upgrade', 'stored');
            if (! $this->orders->chargeRenewal($m, $order)) {
                throw new RefusedException('Your card was declined for the upgrade. Nothing has changed.', 'membership_payment_failed');
            }

            return $order->refresh();
        }

        return $this->orders->membershipPayment($user, $m, $tier, $pence, 'upgrade', 'card');
    }

    /** Move to a cheaper tier from the next renewal. */
    public function downgrade(User $user, MembershipTier $tier): void
    {
        $m = $this->current($user);
        if (! $m) {
            throw new RefusedException('You don’t have a membership.', 'membership_none');
        }
        if ($tier->price_pence >= $m->tier->price_pence || ! $tier->active) {
            throw new RefusedException('Choose a cheaper tier that’s open to join.', 'membership_not_downgrade');
        }
        $m->forceFill(['pending_tier_id' => $tier->id])->save();
    }

    /** Stop at the end of the paid month (perks carry on until then). */
    public function cancel(User $user): Membership
    {
        $m = $this->current($user) ?? throw new RefusedException('You don’t have a membership.', 'membership_none');
        $m->forceFill(['cancel_at_period_end' => true, 'pending_tier_id' => null])->save();

        return $m;
    }

    /** Keep a membership that was set to end. */
    public function keep(User $user): Membership
    {
        $m = $this->current($user) ?? throw new RefusedException('You don’t have a membership.', 'membership_none');
        $this->assertCanPay($user);
        $m->forceFill(['cancel_at_period_end' => false])->save();

        return $m;
    }

    /** Pay a failed renewal now with a new card payment (starts a new recurring parent). */
    public function payNow(User $user): Order
    {
        $this->assertCanPay($user);
        $m = $this->current($user);
        if (! $m || $m->status !== 'past_due') {
            throw new RefusedException('There’s nothing to pay on your membership.', 'membership_nothing_due');
        }
        $tier = $m->pendingTier ?? $m->tier;

        return $this->orders->membershipPayment($user, $m, $tier, $tier->price_pence, 'resume', 'card');
    }

    /** Self-exclusion, a break or a closed account: the membership ends at once, with no further charges. */
    public function stopFor(User $user, string $reason): void
    {
        Membership::query()->where('user_id', $user->id)->whereIn('status', [...Membership::CURRENT, 'pending'])
            ->update(['status' => 'cancelled', 'ended_at' => now(), 'end_reason' => $reason, 'cancel_at_period_end' => false]);
    }

    /** An admin ends a membership at once (logged). */
    public function end(User $admin, Membership $m, string $reason): void
    {
        $m->forceFill(['status' => 'cancelled', 'ended_at' => now(), 'end_reason' => 'admin: '.$reason])->save();
        AdminAction::record($admin, 'membership.end', (string) $m->id, $reason);
    }

    /** Orders::markPaid calls this for every paid membership order. */
    public function orderPaid(Order $order): void
    {
        $m = Membership::query()->lockForUpdate()->find($order->membership_id);
        $tier = MembershipTier::query()->find($order->membership_tier_id);
        if (! $m || ! $tier) {
            return;
        }
        $card = $order->card_pence > 0 && $order->gateway_ref;
        switch ($order->membership_kind) {
            case 'join':
            case 'resume':
                if (in_array($m->status, ['cancelled', 'lapsed'], true) && $order->membership_kind === 'join') {
                    Log::warning('Membership paid after it was replaced', ['order' => $order->number]);
                    app(Fulfilment::class)->flag($order, 'Membership payment arrived after the membership had ended: refund it.');

                    return;
                }
                $start = now();
                $m->forceFill([
                    'status' => 'active', 'membership_tier_id' => $tier->id, 'pending_tier_id' => null,
                    'started_at' => $m->started_at ?? $start, 'current_period_start' => $start, 'current_period_end' => $start->copy()->addMonthNoOverflow(),
                    'failed_attempts' => 0, 'next_attempt_at' => null, 'pay_with' => $card ? 'card' : 'wallet',
                    'gateway_parent_ref' => $card ? $order->gateway_ref : $m->gateway_parent_ref,
                ])->save();
                $this->grantPerks($m->refresh());
                break;
            case 'renewal':
                // Paid on time: the new month follows on from the last. Paid late (after a failure): it starts now.
                $from = $m->status === 'active' && $m->current_period_end ? $m->current_period_end->copy() : now();
                $m->forceFill(['status' => 'active', 'membership_tier_id' => $tier->id, 'pending_tier_id' => null,
                    'current_period_start' => $from, 'current_period_end' => $from->copy()->addMonthNoOverflow(),
                    'failed_attempts' => 0, 'next_attempt_at' => null])->save();
                $this->grantPerks($m->refresh());
                break;
            case 'upgrade':
                $this->switchTier($m, $tier);
                break;
        }
    }

    /**
     * Every few minutes: renew memberships whose month is up, retry failed renewals, end cancelled ones.
     * Returns [renewed, failed, ended].
     */
    public function renewDue(): array
    {
        $done = [0, 0, 0];
        $due = Membership::query()->with('tier', 'pendingTier', 'user')
            ->where(fn ($q) => $q->where('status', 'active')->where('current_period_end', '<=', now())
                ->orWhere(fn ($q) => $q->where('status', 'past_due')->where('next_attempt_at', '<=', now())))
            ->limit(200)->get();
        foreach ($due as $m) {
            if ($m->cancel_at_period_end) {
                $m->forceFill(['status' => 'cancelled', 'ended_at' => now(), 'end_reason' => 'cancelled by the player'])->save();
                $done[2]++;

                continue;
            }
            if ($block = $this->check->block($m->user)) {
                $m->forceFill(['status' => 'cancelled', 'ended_at' => now(), 'end_reason' => 'account can’t play: '.$block])->save();
                $done[2]++;

                continue;
            }
            // A downgrade applies now. A tier switched off for new joiners still renews for its members.
            $tier = $m->pendingTier && $m->pendingTier->active ? $m->pendingTier : $m->tier;
            if ($this->renewOne($m, $tier)) {
                $done[0]++;
            } else {
                $done[1]++;
            }
        }

        return $done;
    }

    private function renewOne(Membership $m, MembershipTier $tier): bool
    {
        $user = $m->user;
        try {
            $b = $this->wallet->balances($user);
            if ($m->pay_with === 'wallet' && $b['deposit'] + $b['winnings'] >= $tier->price_pence) {
                $this->orders->membershipPayment($user, $m, $tier, $tier->price_pence, 'renewal', 'wallet');

                return true;
            }
            if ($m->gateway_parent_ref) {
                $order = $this->orders->membershipPayment($user, $m, $tier, $tier->price_pence, 'renewal', 'stored');
                if ($this->orders->chargeRenewal($m, $order)) {
                    return true;
                }
            }
        } catch (RefusedException $e) {
            Log::info('Membership renewal refused', ['membership' => $m->id, 'reason' => $e->reason]);
        }
        $attempts = $m->failed_attempts + 1;
        $m->forceFill($attempts >= self::MAX_ATTEMPTS
            ? ['status' => 'lapsed', 'failed_attempts' => $attempts, 'ended_at' => now(), 'end_reason' => 'renewal payments failed', 'next_attempt_at' => null]
            : ['status' => 'past_due', 'failed_attempts' => $attempts, 'next_attempt_at' => now()->addDays(self::RETRY_DAYS)])->save();

        return false;
    }

    /** This month's perks, once per membership month (references make repeats harmless). */
    private function grantPerks(Membership $m): void
    {
        $tier = $m->tier;
        $period = $m->current_period_start->format('Ymd');
        if ($tier->monthly_bonus_credit_pence > 0) {
            $this->wallet->credit($m->user_id, 'bonus', $tier->monthly_bonus_credit_pence, 'Membership: '.$tier->name,
                "membership:{$m->id}:{$period}:credit", null, $m->current_period_end->copy()->addDays(Settings::bonusExpiryDays()));
        }
        if ($tier->monthly_free_tickets > 0) {
            Voucher::query()->firstOrCreate(['user_id' => $m->user_id, 'source' => 'membership', 'referral_id' => null,
                'expires_at' => $m->current_period_end], ['tickets' => $tier->monthly_free_tickets, 'status' => 'available']);
        }
    }

    private function switchTier(Membership $m, MembershipTier $tier): void
    {
        $m->forceFill(['membership_tier_id' => $tier->id, 'pending_tier_id' => null])->save();
    }

    /** $monthlyPence for what's left of the current month. */
    private function prorated(Membership $m, int $monthlyPence): int
    {
        $total = max(1, $m->current_period_start->diffInSeconds($m->current_period_end));
        $left = max(0, now()->diffInSeconds($m->current_period_end, false));

        return (int) floor($monthlyPence * min(1, $left / $total));
    }

    private function assertCanPay(User $user): void
    {
        if ($block = $this->check->block($user)) {
            throw new RefusedException(PlayCheck::MESSAGES[$block], $block, 403);
        }
    }

    private function assertWallet(User $user, string $payWith, int $pence): void
    {
        if (! in_array($payWith, ['card', 'wallet'], true)) {
            throw new RefusedException('Choose card or wallet.', 'membership_pay_with');
        }
        $b = $this->wallet->balances($user);
        if ($payWith === 'wallet' && $b['deposit'] + $b['winnings'] < $pence) {
            throw new RefusedException('Your wallet’s cash balance doesn’t cover this. Bonus credit can’t be used for memberships.', 'not_enough_wallet');
        }
    }

    /** The player's membership page (GET /api/me/membership). */
    public function summary(User $user): array
    {
        $m = $this->current($user);
        $tiers = MembershipTier::query()->where('active', true)->orderBy('sort')->orderBy('price_pence')->get();
        $present = fn (MembershipTier $t) => ['id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'pricePence' => $t->price_pence,
            'perks' => $t->perkList(), 'ineligible' => $this->ineligible($user, $t)];

        return [
            'membership' => $m ? [
                'id' => $m->id, 'status' => $m->status, 'tier' => $present($m->tier), 'payWith' => $m->pay_with,
                'perksOn' => $m->perksOn(), 'periodEnd' => $m->current_period_end?->toIso8601ZuluString(),
                'cancelAtPeriodEnd' => $m->cancel_at_period_end, 'nextTier' => $m->pendingTier?->name,
                'nextAttemptAt' => $m->next_attempt_at?->toIso8601ZuluString(), 'startedAt' => $m->started_at?->toIso8601ZuluString(),
            ] : null,
            'tiers' => $tiers->map($present)->values()->all(),
        ];
    }
}

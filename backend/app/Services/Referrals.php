<?php

namespace App\Services;

use App\Exceptions\RefusedException;
use App\Models\Competition;
use App\Models\Order;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\ReferralScheme;
use App\Models\Setting;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\ReferralRewarded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refer a Friend. A friend is linked at sign-up only. The referral qualifies on the friend's first paid
 * ticket order if that order is at least referral_min_purchase_pence of real money (card, deposit or
 * winnings; bonus credit and free tickets don't count). Then the fraud checks run, then the referrer's
 * monthly cap, then both sides get their reward: free-ticket vouchers (default) or bonus credit.
 */
final class Referrals
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I

    public function __construct(private Wallet $wallet, private CompetitionState $state, private Orders $orders, private PlayCheck $check) {}

    /** The player's own share code, made the first time it's needed. */
    public function codeFor(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (User::query()->where('referral_code', $code)->exists());
        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    /** "ab cd 12 34" and "ABCD1234" are the same code. */
    public static function normalise(?string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    public function referrerFor(?string $code): ?User
    {
        $code = self::normalise($code);

        return $code === '' ? null : User::query()->where('referral_code', $code)->whereNull('closed_at')->first();
    }

    /** Called once, when the friend's account is created. A referral can't be added afterwards. */
    public function attach(User $friend, ?string $code, ?string $deviceId, ?string $ip): ?Referral
    {
        $friend->forceFill(['signup_device_hash' => self::hash($deviceId), 'signup_ip_hash' => self::hash($ip)])->save();
        if (! Settings::referralsOn() || ! ($referrer = $this->referrerFor($code)) || $referrer->id === $friend->id) {
            return null;
        }
        $scheme = $this->schemeFor($referrer);
        if ($scheme === false) {
            return null; // schemes are set up, but none is running for this referrer
        }
        $friend->forceFill(['referred_by_id' => $referrer->id])->save();

        return Referral::query()->create(['referrer_id' => $referrer->id, 'friend_id' => $friend->id, 'status' => 'pending', 'referral_scheme_id' => $scheme?->id]);
    }

    /**
     * The scheme a new referral from $referrer joins (SOW 2.13): the first one running that lets them refer.
     * Null when no schemes are set up (the settings in Admin → Settings apply); false when none applies.
     */
    public function schemeFor(User $referrer): ReferralScheme|false|null
    {
        $schemes = ReferralScheme::query()->orderBy('id')->get();
        if ($schemes->isEmpty()) {
            return null;
        }
        $member = null;
        foreach ($schemes as $s) {
            if (! $s->isOn()) {
                continue;
            }
            if ($s->who_can_refer === 'members') {
                $member ??= app(Memberships::class)->perkTier($referrer) !== null;
                if (! $member) {
                    continue;
                }
            }

            return $s;
        }

        return false;
    }

    /** Orders::markPaid calls this for every paid ticket order. */
    public function orderPaid(Order $order): void
    {
        if (! Settings::referralsOn() || $order->total_pence < 1) {
            return; // free (voucher) orders are never a qualifying purchase
        }
        $ref = Referral::query()->with('scheme')->where('friend_id', $order->user_id)->where('status', 'pending')->first();
        if (! $ref) {
            return;
        }
        if ($ref->scheme) {
            $this->schemeOrderPaid($ref, $order);

            return;
        }
        $earlier = Order::query()->where('user_id', $order->user_id)->where('order_type', 'tickets')->where('status', 'paid')
            ->where('total_pence', '>', 0)->where('id', '!=', $order->id)->exists();
        $realMoney = $order->card_pence + $order->paid_deposit_pence + $order->paid_winnings_pence;
        $min = Settings::bounded('referral_min_purchase_pence', 0, 100000);
        if ($earlier || $realMoney < $min) {
            // Only the friend's first order counts (owner's default, see docs/overnight-log.md).
            $ref->forceFill(['status' => 'missed', 'reasons' => [$earlier ? 'first order already made' : 'first order under the minimum']])->save();

            return;
        }
        $ref->forceFill(['qualifying_order_id' => $order->id, 'qualified_at' => now()])->save();
        $this->decide($ref, $order);
    }

    /** A scheme referral: what the friend must do is set by the scheme. */
    private function schemeOrderPaid(Referral $ref, Order $order): void
    {
        $s = $ref->scheme;
        if ($s->qualifying === 'membership') {
            return; // qualifies on joining a membership (membershipJoined)
        }
        $realMoney = $order->card_pence + $order->paid_deposit_pence + $order->paid_winnings_pence;
        if ($s->qualifying === 'first_order') {
            $earlier = Order::query()->where('user_id', $order->user_id)->where('order_type', 'tickets')->where('status', 'paid')
                ->where('total_pence', '>', 0)->where('id', '!=', $order->id)->exists();
            if ($earlier || $realMoney < $s->min_spend_pence) {
                $ref->forceFill(['status' => 'missed', 'reasons' => [$earlier ? 'first order already made' : 'first order under the minimum']])->save();

                return;
            }
        } elseif ($realMoney < $s->min_spend_pence) {
            return; // any_order: still waiting for an order that reaches the minimum
        }
        $ref->forceFill(['qualifying_order_id' => $order->id, 'qualified_at' => now()])->save();
        $this->decide($ref, $order);
    }

    /** Memberships::orderPaid calls this when a player's membership starts. */
    public function membershipJoined(Order $order): void
    {
        if (! Settings::referralsOn()) {
            return;
        }
        $ref = Referral::query()->with('scheme')->where('friend_id', $order->user_id)->where('status', 'pending')->first();
        if (! $ref || $ref->scheme?->qualifying !== 'membership' || $order->total_pence < $ref->scheme->min_spend_pence) {
            return;
        }
        $ref->forceFill(['qualifying_order_id' => $order->id, 'qualified_at' => now()])->save();
        $this->decide($ref, $order);
    }

    /** Fraud checks, then the monthly cap, then the rewards. */
    private function decide(Referral $ref, Order $order): void
    {
        $friend = $ref->friend;
        $referrer = $ref->referrer;
        $block = $this->fraudReasons($referrer, $friend, $order);
        if ($block) {
            $ref->forceFill(['status' => 'blocked', 'reasons' => $block])->save();

            return;
        }
        $flags = $this->flagReasons($referrer, $friend);
        if ($flags) {
            $ref->forceFill(['status' => 'review', 'reasons' => $flags])->save();

            return;
        }
        $this->reward($ref);
    }

    /** @return list<string> why the reward is blocked on both sides */
    public function fraudReasons(User $referrer, User $friend, ?Order $order): array
    {
        $why = [];
        $card = $order?->card_hash;
        if ($card && Order::query()->where('user_id', $referrer->id)->where('card_hash', $card)->exists()) {
            $why[] = 'same card';
        }
        if ($friend->signup_device_hash && in_array($friend->signup_device_hash, [$referrer->signup_device_hash], true)) {
            $why[] = 'same device';
        }
        if ($friend->signup_ip_hash && in_array($friend->signup_ip_hash, [$referrer->signup_ip_hash], true)) {
            $why[] = 'same sign-up IP';
        }
        $addr = fn (User $u) => $u->postcode && $u->address_line1 ? strtoupper(preg_replace('/\s+/', '', $u->postcode)).'|'.strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $u->address_line1)) : null;
        if (($a = $addr($friend)) && $a === $addr($referrer)) {
            $why[] = 'same address';
        }
        if (self::emailKey($friend->email) === self::emailKey($referrer->email)) {
            $why[] = 'same email pattern';
        }

        return $why;
    }

    /** @return list<string> reasons to send it to the review queue (many referrals from one IP or device) */
    private function flagReasons(User $referrer, User $friend): array
    {
        $limit = Settings::bounded('referral_flag_after', 1, 1000);
        $why = [];
        foreach (['signup_ip_hash' => 'many referrals from one IP', 'signup_device_hash' => 'many referrals from one device'] as $col => $label) {
            if ($friend->$col && User::query()->where('referred_by_id', $referrer->id)->where($col, $friend->$col)->count() >= $limit) {
                $why[] = $label;
            }
        }

        return $why;
    }

    /** Both sides' rewards. Over the referrer's monthly cap, only the friend's. */
    public function reward(Referral $ref): void
    {
        if ($ref->scheme) {
            $this->rewardScheme($ref);

            return;
        }
        DB::transaction(function () use ($ref) {
            $cap = Settings::bounded('referral_monthly_cap', 0, 1000);
            $thisMonth = Referral::query()->where('referrer_id', $ref->referrer_id)->where('status', 'rewarded')
                ->where('rewarded_at', '>=', now('Europe/London')->startOfMonth()->utc())->count();
            $capped = $thisMonth >= $cap;
            $this->give($ref, $ref->friend, 'friend');
            if (! $capped) {
                $this->give($ref, $ref->referrer, 'referrer');
            }
            $ref->forceFill(['status' => $capped ? 'capped' : 'rewarded', 'rewarded_at' => now()])->save();
        });
        foreach ($ref->status === 'rewarded' ? [$ref->referrer, $ref->friend] : [$ref->friend] as $u) {
            try {
                $u->notify(new ReferralRewarded($ref->fresh(), $u->id === $ref->referrer_id));
            } catch (\Throwable) {
                Log::warning('Referral notice not sent', ['referral' => $ref->id]);
            }
        }
    }

    /** A scheme referral's rewards: friend, referrer (within the monthly cap) and any levels further up. */
    private function rewardScheme(Referral $ref): void
    {
        $s = $ref->scheme;
        DB::transaction(function () use ($ref, $s) {
            if ($s->total_cap !== null && $s->referrals()->where('status', 'rewarded')->count() >= $s->total_cap) {
                $ref->forceFill(['status' => 'capped', 'reasons' => [...($ref->reasons ?? []), 'scheme limit reached']])->save();

                return;
            }
            $thisMonth = ReferralReward::query()->where('user_id', $ref->referrer_id)->where('level', 1)->where('status', 'given')
                ->where('created_at', '>=', now('Europe/London')->startOfMonth()->utc())->count();
            $capped = $s->monthly_cap !== null && $thisMonth >= $s->monthly_cap;
            $this->giveScheme($ref, $ref->friend, 0, $s->friend_reward_type, (int) $s->friend_reward_amount);
            if (! $capped) {
                $this->giveScheme($ref, $ref->referrer, 1, $s->referrer_reward_type, (int) $s->referrer_reward_amount);
                // Multi-level: the referrer's own referrer is level 2, and so on (SOW 2.13).
                $up = $ref->referrer;
                foreach (array_values($s->levels ?? []) as $i => $lvl) {
                    $up = $up?->referred_by_id ? User::query()->find($up->referred_by_id) : null;
                    if (! $up || $up->closed_at) {
                        break;
                    }
                    $this->giveScheme($ref, $up, $i + 2, (string) $lvl['type'], (int) $lvl['amount']);
                }
            }
            $ref->forceFill(['status' => $capped ? 'capped' : 'rewarded', 'rewarded_at' => now()])->save();
        });
        foreach ($ref->status === 'rewarded' ? [$ref->referrer, $ref->friend] : [$ref->friend] as $u) {
            try {
                $u->notify(new ReferralRewarded($ref->fresh(), $u->id === $ref->referrer_id));
            } catch (\Throwable) {
                Log::warning('Referral notice not sent', ['referral' => $ref->id]);
            }
        }
    }

    private function giveScheme(Referral $ref, User $user, int $level, string $type, int $amount): void
    {
        if ($type === 'none' || $amount < 1 || ReferralReward::query()->where(['referral_id' => $ref->id, 'user_id' => $user->id, 'level' => $level])->exists()) {
            return;
        }
        $reward = ReferralReward::query()->create(['referral_id' => $ref->id, 'user_id' => $user->id, 'level' => $level, 'type' => $type, 'amount' => $amount]);
        $label = 'Refer a friend'.($level >= 2 ? " (level {$level})" : '');
        match ($type) {
            'credit' => $this->wallet->credit($user, 'bonus', $amount, $label, "referral:{$ref->id}:L{$level}:{$user->id}", null, now()->addDays(Settings::bonusExpiryDays())),
            'tickets' => $reward->forceFill(['voucher_id' => Voucher::query()->create(['referral_id' => $ref->id, 'user_id' => $user->id, 'source' => 'referral',
                'tickets' => min($amount, 1000), 'status' => 'available', 'expires_at' => now()->addDays(Settings::bounded('referral_voucher_days', 1, 365))])->id])->save(),
            'points' => ($p = $ref->scheme->loyaltyProgramme()) ? app(Loyalty::class)->award($user->id, $p, $amount, $label, "referral:{$ref->id}:L{$level}:{$user->id}") : null,
            default => null,
        };
    }

    private function give(Referral $ref, User $user, string $side): void
    {
        if (Setting::get('referral_reward_type') === 'credit') {
            $pence = Settings::bounded('referral_reward_credit_pence', 1, 100000);
            $this->wallet->credit($user, 'bonus', $pence, 'Refer a friend', "referral:{$ref->id}:{$side}", null, now()->addDays(Settings::bonusExpiryDays()));

            return;
        }
        Voucher::query()->firstOrCreate(['referral_id' => $ref->id, 'user_id' => $user->id], [
            'tickets' => Settings::bounded('referral_reward_tickets', 1, 1000), 'status' => 'available',
            'expires_at' => now()->addDays(Settings::bounded('referral_voucher_days', 1, 365)),
        ]);
    }

    public function approve(Referral $ref): void
    {
        if ($ref->status === 'review') {
            $this->reward($ref);
        }
    }

    public function reject(Referral $ref): void
    {
        if ($ref->status === 'review') {
            $ref->forceFill(['status' => 'rejected'])->save();
        }
    }

    /**
     * The qualifying order was refunded or charged back: within the clawback window, unredeemed
     * vouchers are taken back; a redeemed one goes to review (tickets in a closed draw are never touched).
     */
    public function orderReversed(Order $order): void
    {
        $ref = Referral::query()->where('qualifying_order_id', $order->id)->whereIn('status', ['rewarded', 'capped', 'review'])->first();
        if (! $ref || ! $order->paid_at || $order->paid_at->lt(now()->subDays(Settings::bounded('referral_clawback_days', 0, 365)))) {
            return;
        }
        $redeemed = false;
        // Scheme rewards of credit or points come back (as much credit as is still unspent).
        foreach ($ref->rewards()->where('status', 'given')->whereIn('type', ['credit', 'points'])->get() as $rw) {
            if ($rw->type === 'credit') {
                $this->wallet->takeBackBonus($rw->user_id, $rw->amount, 'Refer a friend reversed', "referral-reversed:{$rw->id}");
            } elseif ($p = $ref->scheme?->loyaltyProgramme()) {
                app(Loyalty::class)->award($rw->user_id, $p, -$rw->amount, 'Refer a friend reversed', "referral-reversed:{$rw->id}");
            }
            $rw->forceFill(['status' => 'reversed'])->save();
        }
        foreach ($ref->vouchers as $v) {
            if ($v->status === 'available') {
                $v->forceFill(['status' => 'clawed_back'])->save();
            } elseif ($v->status === 'redeemed') {
                $redeemed = true;
            }
        }
        $ref->forceFill($redeemed ? ['status' => 'review', 'reasons' => [...($ref->reasons ?? []), 'qualifying order refunded after a voucher was used']]
            : ['status' => 'clawed_back', 'reasons' => [...($ref->reasons ?? []), 'qualifying order refunded']])->save();
    }

    /** Use a voucher: its tickets go into a live draw the player picks, issued by Verity like any others. */
    public function redeem(Voucher $voucher, User $user, Competition $c): Order
    {
        return DB::transaction(function () use ($voucher, $user, $c) {
            // The competition row is locked so two redeems can't both take the last of the promo cap.
            $c = Competition::query()->lockForUpdate()->findOrFail($c->id);
            $v = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);
            if ($v->user_id !== $user->id || $v->status !== 'available' || $v->expires_at->isPast()) {
                throw new RefusedException('This voucher can no longer be used.', 'voucher_unavailable');
            }
            if (! in_array($c->id, array_map(fn (Competition $x) => $x->id, $this->drawsFor($v, $user)), true)) {
                throw new RefusedException("That draw can't take these tickets right now. Please choose another.", 'voucher_draw');
            }
            $this->check->assertAllowed($user, 0);
            $order = $this->orders->issueFree($user, $c, $v->tickets, 'voucher');
            $v->forceFill(['status' => 'redeemed', 'competition_id' => $c->id, 'order_id' => $order->id, 'redeemed_at' => now()])->save();

            return $order;
        });
    }

    /** Live draws a voucher can go into: on sale, room for its tickets, within the biggest-bundle share and the draw's promo ticket cap. */
    public function drawsFor(Voucher $v, User $user): array
    {
        return Competition::query()->published()->where('type', 'draw')->orderBy('closes_at')->get()
            ->filter(fn (Competition $c) => (! $v->for_competition_id || $v->for_competition_id === $c->id) && $this->state->status($c) === 'live' && $this->state->canBuy($c, $user->ownerRef()) >= $v->tickets
                && $v->tickets <= Bundles::shareLimit($c) && PromoCap::room($c) >= $v->tickets)
            ->values()->all();
    }

    public function expireVouchers(): int
    {
        return Voucher::query()->where('status', 'available')->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }

    /** @return array{invited:int, qualified:int, rewards:int, leftThisMonth:int, cap:int} */
    public function stats(User $user): array
    {
        $refs = Referral::query()->where('referrer_id', $user->id)->get(['status', 'rewarded_at']);
        $scheme = $this->schemeFor($user);
        $cap = $scheme ? (int) ($scheme->monthly_cap ?? 1000) : Settings::bounded('referral_monthly_cap', 0, 1000);
        $monthStart = now('Europe/London')->startOfMonth()->utc();
        $used = $refs->filter(fn ($r) => $r->status === 'rewarded' && $r->rewarded_at?->gte($monthStart))->count();

        return ['invited' => $refs->count(), 'qualified' => $refs->whereIn('status', Referral::QUALIFIED)->count(),
            'rewards' => $refs->where('status', 'rewarded')->count(), 'leftThisMonth' => max(0, $cap - $used), 'cap' => $cap];
    }

    public static function hash(?string $value): ?string
    {
        return $value === null || $value === '' ? null : hash_hmac('sha256', $value, (string) config('app.key'));
    }

    /** jo.smith+x@gmail.com and josmith@gmail.com are the same person for this check. */
    public static function emailKey(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', strtolower(trim($email)), 2), 2, '');

        return str_replace('.', '', explode('+', $local)[0]).'@'.$domain;
    }
}

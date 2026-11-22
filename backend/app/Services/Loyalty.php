<?php

namespace App\Services;

use App\Exceptions\RefusedException;
use App\Models\AdminAction;
use App\Models\LoyaltyBonusEvent;
use App\Models\LoyaltyEntry;
use App\Models\LoyaltyProgramme;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loyalty points (SOW 2.11). The only place that writes loyalty_entries (append-only): every entry has a unique
 * reference, so repeating an event never counts twice. Points are earned on real money only (card, deposit and
 * winnings, never bonus credit), per £1, at the player's tier multiplier and any competition bonus.
 * A balance is the sum of the player's entries in that programme. Points expire oldest first.
 */
class Loyalty
{
    /** Days a loyalty voucher (free or bonus entries) stays usable. */
    public const VOUCHER_DAYS = 30;

    public function __construct(private Wallet $wallet, private PlayCheck $check) {}

    /** @return Collection<int, LoyaltyProgramme> */
    public function programmes(): Collection
    {
        return LoyaltyProgramme::query()->active()->with(['tiers', 'bonusEvents'])->orderBy('id')->get();
    }

    public function balance(int $userId, int $programmeId): int
    {
        return (int) LoyaltyEntry::query()->where('user_id', $userId)->where('loyalty_programme_id', $programmeId)->sum('points');
    }

    /** Lifetime points earned (earn, bonus and positive adjustments, less reversals): sets the tier. */
    public function lifetime(int $userId, int $programmeId): int
    {
        $q = LoyaltyEntry::query()->where('user_id', $userId)->where('loyalty_programme_id', $programmeId);

        return max(0, (int) (clone $q)->whereIn('kind', ['earn', 'bonus', 'reverse'])->sum('points')
            + (int) (clone $q)->where('kind', 'adjust')->where('points', '>', 0)->sum('points'));
    }

    public function tierFor(int $userId, LoyaltyProgramme $p): ?LoyaltyTier
    {
        $life = $this->lifetime($userId, $p->id);

        return $p->tiers->filter(fn (LoyaltyTier $t) => $t->threshold_points <= $life)->sortByDesc('threshold_points')->first();
    }

    /** Real money in an order: what the card, deposit and winnings paid (never bonus credit). */
    public static function realPence(Order $order): int
    {
        return (int) $order->card_pence + (int) $order->paid_deposit_pence + (int) $order->paid_winnings_pence;
    }

    /** Points for an amount: per whole £1, times the tier multiplier and a competition multiplier (percent). */
    public static function pointsFor(LoyaltyProgramme $p, ?LoyaltyTier $tier, int $pence, int $extraPct = 100): int
    {
        return intdiv($pence * $p->points_per_pound * ($tier?->multiplier_pct ?? 100) * $extraPct, 100 * 100 * 100);
    }

    /** Orders::markPaid calls this for every paid ticket order. */
    public function orderPaid(Order $order): void
    {
        $real = self::realPence($order);
        if ($order->order_type !== 'tickets' || $real < 1) {
            return; // free (voucher) orders and all-bonus orders earn nothing
        }
        $order->loadMissing('lines');
        $lineTotal = max(1, (int) $order->lines->sum('line_pence'));
        $member = 100 + (int) (app(Memberships::class)->perkTier($order->user_id)?->loyalty_bonus_pct ?? 0);
        foreach ($this->programmes() as $p) {
            $tier = $this->tierFor($order->user_id, $p);
            $bonuses = $p->bonusEvents->filter(fn (LoyaltyBonusEvent $e) => $e->kind === 'competition' && $e->isOn())->keyBy('competition_id');
            // Real money is shared across the lines by their price; a competition bonus multiplies its share.
            $points = 0;
            foreach ($order->lines as $line) {
                $share = intdiv($real * (int) $line->line_pence, $lineTotal);
                $points += self::pointsFor($p, $tier, $share, $bonuses->get($line->competition_id)?->multiplier_pct ?? 100);
            }
            $points = intdiv($points * $member, 100); // a membership's loyalty bonus (SOW 2.12)
            if ($points > 0) {
                $this->write($order->user_id, $p, $points, 'earn', 'Order '.$order->number, "order:{$order->id}:p{$p->id}", $order->id);
            }
            $first = $p->bonusEvents->first(fn (LoyaltyBonusEvent $e) => $e->kind === 'first_order' && $e->isOn() && $e->points > 0);
            if ($first) {
                $this->write($order->user_id, $p, $first->points, 'bonus', 'First order bonus', "first-order:{$order->user_id}:p{$p->id}", $order->id);
            }
        }
    }

    /** The order was refunded or charged back: its points come off (the balance can go below zero). */
    public function orderReversed(Order $order): void
    {
        $earned = LoyaltyEntry::query()->where('order_id', $order->id)->whereIn('kind', ['earn', 'bonus'])->get();
        foreach ($earned->groupBy('loyalty_programme_id') as $programmeId => $rows) {
            $p = LoyaltyProgramme::query()->find($programmeId);
            $total = (int) $rows->sum('points');
            if ($p && $total > 0) {
                $this->write($order->user_id, $p, -$total, 'reverse', 'Order '.$order->number.' refunded', "reverse:{$order->id}:p{$p->id}", $order->id, false);
            }
        }
    }

    /** A new account: each active programme's sign-up bonus. */
    public function signedUp(User $user): void
    {
        foreach ($this->programmes() as $p) {
            $e = $p->bonusEvents->first(fn (LoyaltyBonusEvent $e) => $e->kind === 'signup' && $e->isOn() && $e->points > 0);
            if ($e) {
                $this->write($user->id, $p, $e->points, 'bonus', 'Welcome bonus', "signup:{$user->id}:p{$p->id}");
            }
        }
    }

    /** Daily: birthday bonuses for players whose birthday is today (UK time). Returns how many were given. */
    public function birthdays(?Carbon $today = null): int
    {
        $today ??= now('Europe/London');
        $given = 0;
        foreach ($this->programmes() as $p) {
            $e = $p->bonusEvents->first(fn (LoyaltyBonusEvent $e) => $e->kind === 'birthday' && $e->isOn() && $e->points > 0);
            if (! $e) {
                continue;
            }
            User::query()->whereNotNull('date_of_birth')->where('is_admin', false)->whereNull('closed_at')->select(['id', 'date_of_birth'])
                ->chunkById(500, function ($users) use ($p, $e, $today, &$given) {
                    foreach ($users as $u) {
                        $dob = Carbon::parse($u->date_of_birth);
                        $isToday = $dob->month === $today->month && ($dob->day === $today->day
                            || ($dob->month === 2 && $dob->day === 29 && ! $today->isLeapYear() && $today->day === 28));
                        if ($isToday && $this->write($u->id, $p, $e->points, 'bonus', 'Birthday bonus', "birthday:{$u->id}:{$today->year}:p{$p->id}")) {
                            $given++;
                        }
                    }
                });
        }

        return $given;
    }

    /**
     * Daily: points past their expiry date come off, oldest first. What's due is the expired points still
     * unspent: everything earned with an expiry before now, less everything already taken off (spent,
     * expired, reversed or adjusted down), never below zero.
     */
    public function expire(): int
    {
        $count = 0;
        $pairs = LoyaltyEntry::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->where('points', '>', 0)
            ->select('user_id', 'loyalty_programme_id')->distinct()->get();
        foreach ($pairs as $pair) {
            $q = LoyaltyEntry::query()->where('user_id', $pair->user_id)->where('loyalty_programme_id', $pair->loyalty_programme_id);
            $expired = (int) (clone $q)->where('points', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', now())->sum('points');
            $takenOff = -(int) (clone $q)->where('points', '<', 0)->sum('points');
            $due = min($expired - $takenOff, $this->balance($pair->user_id, $pair->loyalty_programme_id));
            $p = LoyaltyProgramme::query()->find($pair->loyalty_programme_id);
            if ($due > 0 && $p && $this->write($pair->user_id, $p, -$due, 'expire', 'Points expired', 'expire:'.$pair->user_id.':p'.$p->id.':'.now()->format('YmdHis'), null, false)) {
                $count++;
            }
        }

        return $count;
    }

    /** Spend points on a reward. */
    public function redeem(User $user, LoyaltyReward $reward): LoyaltyRedemption
    {
        if ($block = $this->check->block($user)) {
            throw new RefusedException(PlayCheck::MESSAGES[$block], $block);
        }

        return DB::transaction(function () use ($user, $reward) {
            $this->wallet->lock($user->id);
            $r = LoyaltyReward::query()->lockForUpdate()->with('programme.tiers')->findOrFail($reward->id);
            $p = $r->programme;
            if (! $r->active || ! $p->active) {
                throw new RefusedException('This reward isn’t available right now.', 'reward_unavailable');
            }
            if ($r->stock !== null && $r->stock < 1) {
                throw new RefusedException('This reward has run out.', 'reward_out_of_stock');
            }
            if ($r->min_tier_id && ! $this->reachedTier($user->id, $p, $r->min_tier_id)) {
                throw new RefusedException('This reward is for a higher tier.', 'reward_tier');
            }
            if ($this->balance($user->id, $p->id) < $r->points_cost) {
                throw new RefusedException('You don’t have enough points for this reward yet.', 'not_enough_points');
            }
            $red = LoyaltyRedemption::query()->create(['user_id' => $user->id, 'loyalty_reward_id' => $r->id, 'points' => $r->points_cost,
                'status' => $r->type === 'custom' ? 'pending' : 'done']);
            $this->write($user->id, $p, -$r->points_cost, 'redeem', 'Reward: '.$r->name, "redeem:{$red->id}", null, false);
            if ($r->stock !== null) {
                $r->decrement('stock');
            }
            match ($r->type) {
                'site_credit' => $this->wallet->credit($user, 'bonus', (int) $r->credit_pence, 'Loyalty reward: '.$r->name, "loyalty:{$red->id}",
                    null, now()->addDays(Settings::bonusExpiryDays())),
                'free_entries', 'bonus_entries' => $red->forceFill(['voucher_id' => Voucher::query()->create([
                    'user_id' => $user->id, 'source' => 'loyalty', 'tickets' => (int) $r->tickets, 'status' => 'available',
                    'for_competition_id' => $r->type === 'bonus_entries' ? $r->competition_id : null,
                    'expires_at' => now()->addDays(self::VOUCHER_DAYS),
                ])->id])->save(),
                default => null,
            };

            return $red;
        });
    }

    /** The Client fulfilled a custom reward. */
    public function fulfil(User $admin, LoyaltyRedemption $red, ?string $note): void
    {
        if ($red->status !== 'pending') {
            throw new \DomainException('Only a reward waiting to be fulfilled can be marked done.');
        }
        $red->forceFill(['status' => 'fulfilled', 'fulfilled_at' => now(), 'note' => $note])->save();
        AdminAction::record($admin, 'loyalty.fulfil', (string) $red->id, $note);
    }

    /** A custom reward that can't be fulfilled: the points go back. */
    public function cancel(User $admin, LoyaltyRedemption $red, string $reason): void
    {
        DB::transaction(function () use ($admin, $red, $reason) {
            $red = LoyaltyRedemption::query()->lockForUpdate()->with('reward.programme')->findOrFail($red->id);
            if ($red->status !== 'pending') {
                throw new \DomainException('Only a reward waiting to be fulfilled can be cancelled.');
            }
            $red->forceFill(['status' => 'cancelled', 'note' => $reason])->save();
            $this->write($red->user_id, $red->reward->programme, $red->points, 'refund', 'Reward cancelled: '.$red->reward->name, "cancel:{$red->id}", null, false);
            if ($red->reward->stock !== null) {
                $red->reward->increment('stock');
            }
            AdminAction::record($admin, 'loyalty.cancel', (string) $red->id, $reason);
        });
    }

    /** An admin adds or takes off points, with a reason (logged). */
    public function adjust(User $admin, User $player, LoyaltyProgramme $p, int $points, string $reason): void
    {
        if ($points === 0) {
            throw new \DomainException('Enter a number of points other than 0.');
        }
        $this->write($player->id, $p, $points, 'adjust', Str::limit('Adjusted: '.$reason, 160, ''), 'adjust:'.Str::uuid(), null, $points > 0);
        AdminAction::record($admin, 'loyalty.adjust', (string) $player->id, $reason, ['programme' => $p->id, 'points' => $points]);
    }

    /**
     * Points the basket would earn in each active programme if it's all paid with real money (shown in the basket).
     *
     * @param  array<string, int>  $pencePerCompetition
     * @return list<array{programme:string, points:int}>
     */
    public function estimate(?User $user, array $pencePerCompetition, int $totalPence): array
    {
        $sum = max(1, array_sum($pencePerCompetition));
        $member = 100 + (int) ($user ? (app(Memberships::class)->perkTier($user)?->loyalty_bonus_pct ?? 0) : 0);
        $out = [];
        foreach ($this->programmes() as $p) {
            $tier = $user ? $this->tierFor($user->id, $p) : null;
            $bonuses = $p->bonusEvents->filter(fn (LoyaltyBonusEvent $e) => $e->kind === 'competition' && $e->isOn())->keyBy('competition_id');
            $points = 0;
            foreach ($pencePerCompetition as $cid => $pence) {
                $points += self::pointsFor($p, $tier, intdiv($totalPence * $pence, $sum), $bonuses->get($cid)?->multiplier_pct ?? 100);
            }
            $points = intdiv($points * $member, 100);
            if ($points > 0) {
                $out[] = ['programme' => $p->name, 'points' => $points];
            }
        }

        return $out;
    }

    /** The player's loyalty page (GET /api/me/loyalty). */
    public function summary(User $user): array
    {
        $programmes = $this->programmes()->load('rewards.minTier')->map(function (LoyaltyProgramme $p) use ($user) {
            $balance = $this->balance($user->id, $p->id);
            $life = $this->lifetime($user->id, $p->id);
            $tier = $this->tierFor($user->id, $p);
            $next = $p->tiers->filter(fn (LoyaltyTier $t) => $t->threshold_points > $life)->sortBy('threshold_points')->first();
            $soon = (int) LoyaltyEntry::query()->where('user_id', $user->id)->where('loyalty_programme_id', $p->id)->where('points', '>', 0)
                ->whereBetween('expires_at', [now(), now()->addDays(30)])->sum('points');

            return [
                'id' => $p->id, 'name' => $p->name, 'description' => $p->description,
                'balance' => $balance, 'lifetime' => $life, 'pointsPerPound' => $p->points_per_pound, 'expiryDays' => $p->expiry_days,
                'expiringSoon' => min($soon, max(0, $balance)),
                'tier' => $tier ? ['name' => $tier->name, 'benefits' => $tier->benefits, 'multiplierPct' => $tier->multiplier_pct] : null,
                'nextTier' => $next ? ['name' => $next->name, 'pointsToGo' => $next->threshold_points - $life] : null,
                'tiers' => $p->tiers->map(fn (LoyaltyTier $t) => ['name' => $t->name, 'thresholdPoints' => $t->threshold_points,
                    'multiplierPct' => $t->multiplier_pct, 'benefits' => $t->benefits])->values()->all(),
                'rewards' => $p->rewards->where('active', true)->map(fn (LoyaltyReward $r) => [
                    'id' => $r->id, 'name' => $r->name, 'description' => $r->description, 'type' => $r->type, 'pointsCost' => $r->points_cost,
                    'creditPence' => $r->credit_pence, 'tickets' => $r->tickets, 'minTier' => $r->minTier?->name,
                    'available' => ($r->stock === null || $r->stock > 0) && (! $r->min_tier_id || $this->reachedTier($user->id, $p, $r->min_tier_id)),
                    'affordable' => $balance >= $r->points_cost,
                ])->values()->all(),
                'history' => LoyaltyEntry::query()->where('user_id', $user->id)->where('loyalty_programme_id', $p->id)->orderByDesc('id')->limit(30)->get()
                    ->map(fn (LoyaltyEntry $e) => ['points' => $e->points, 'kind' => $e->kind, 'reason' => $e->reason, 'at' => $e->created_at?->toIso8601String(),
                        'expiresAt' => $e->points > 0 ? $e->expires_at?->toIso8601String() : null])->all(),
            ];
        })->values()->all();

        return [
            'programmes' => $programmes,
            'redemptions' => LoyaltyRedemption::query()->where('user_id', $user->id)->with('reward')->orderByDesc('id')->limit(20)->get()
                ->map(fn (LoyaltyRedemption $r) => ['id' => $r->id, 'reward' => $r->reward?->name, 'points' => $r->points, 'status' => $r->status,
                    'voucherId' => $r->voucher_id, 'at' => $r->created_at?->toIso8601String()])->all(),
        ];
    }

    private function reachedTier(int $userId, LoyaltyProgramme $p, int $tierId): bool
    {
        $need = $p->tiers->firstWhere('id', $tierId);

        return $need === null || $this->lifetime($userId, $p->id) >= $need->threshold_points;
    }

    /** Write one entry once. Returns false if that reference already exists. */
    private function write(int $userId, LoyaltyProgramme $p, int $points, string $kind, string $reason, string $reference, ?string $orderId = null, bool $expires = true): bool
    {
        if (LoyaltyEntry::query()->where('reference', $reference)->exists()) {
            return false;
        }
        try {
            LoyaltyEntry::query()->create(['user_id' => $userId, 'loyalty_programme_id' => $p->id, 'points' => $points, 'kind' => $kind,
                'reason' => Str::limit($reason, 160, ''), 'reference' => $reference, 'order_id' => $orderId,
                'expires_at' => $expires && $points > 0 && $p->expiry_days ? now()->addDays($p->expiry_days) : null]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}

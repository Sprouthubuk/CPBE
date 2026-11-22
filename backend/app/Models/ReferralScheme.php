<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A referral scheme (SOW 2.13): who can refer, what the friend must do, each side's reward, caps, dates and
 * optional rewards further up the chain (levels: level 2 is the referrer's own referrer, and so on).
 * Multi-level schemes can raise legal issues: the Client takes legal advice before switching one on.
 */
class ReferralScheme extends Model
{
    public const REWARDS = ['tickets' => 'Free entries (voucher)', 'credit' => 'Bonus credit (pence)', 'points' => 'Loyalty points', 'none' => 'Nothing'];

    public const QUALIFYING = ['first_order' => 'Their first paid order', 'any_order' => 'Any paid order (the first one that qualifies)', 'membership' => 'Joining a membership'];

    public const MAX_LEVELS = 5;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'levels' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (ReferralScheme $s) {
            foreach ([$s->referrer_reward_type, $s->friend_reward_type, ...array_column($s->levels ?? [], 'type')] as $type) {
                if (! isset(self::REWARDS[$type])) {
                    throw new \DomainException('Unknown reward type.');
                }
                if ($type === 'points' && ! $s->loyalty_programme_id) {
                    throw new \DomainException('Choose the loyalty programme for points rewards.');
                }
            }
            if (count($s->levels ?? []) > self::MAX_LEVELS - 1) {
                throw new \DomainException('At most '.self::MAX_LEVELS.' levels.');
            }
        });
    }

    public function isOn(): bool
    {
        return $this->active && (! $this->starts_at || $this->starts_at->isPast()) && (! $this->ends_at || $this->ends_at->isFuture());
    }

    public function loyaltyProgramme(): ?LoyaltyProgramme
    {
        return $this->loyalty_programme_id ? LoyaltyProgramme::query()->find($this->loyalty_programme_id) : null;
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    public static function describe(string $type, int $amount): string
    {
        return match ($type) {
            'tickets' => $amount.' free '.($amount === 1 ? 'entry' : 'entries'),
            'credit' => '£'.number_format($amount / 100, 2).' bonus credit',
            'points' => number_format($amount).' loyalty points',
            default => '',
        };
    }
}

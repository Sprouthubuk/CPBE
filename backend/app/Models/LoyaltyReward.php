<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something points buy (SOW 2.11): site credit (bonus wallet), free entries (a voucher for any live draw),
 * bonus entries (a voucher for one named draw) or a custom reward the Client fulfils itself.
 */
class LoyaltyReward extends Model
{
    public const TYPES = [
        'site_credit' => 'Site credit',
        'free_entries' => 'Free entries (any live draw)',
        'bonus_entries' => 'Bonus entries (one draw)',
        'custom' => 'Custom reward (fulfilled by you)',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (LoyaltyReward $r) {
            if (! isset(self::TYPES[$r->type])) {
                throw new \DomainException('Unknown reward type.');
            }
            if ($r->points_cost < 1) {
                throw new \DomainException('A reward costs at least 1 point.');
            }
            match ($r->type) {
                'site_credit' => $r->credit_pence >= 1 ?: throw new \DomainException('Site credit needs an amount.'),
                'free_entries' => $r->tickets >= 1 ?: throw new \DomainException('Free entries need a number of tickets.'),
                'bonus_entries' => $r->tickets >= 1 && $r->competition_id ?: throw new \DomainException('Bonus entries need tickets and a draw.'),
                default => true,
            };
        });
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgramme::class, 'loyalty_programme_id');
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function minTier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'min_tier_id');
    }
}

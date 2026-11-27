<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One automatic pricing or offer rule on a competition (amendment 5). Applied only by App\Services\PriceRules. */
class CompetitionPriceRule extends Model
{
    public const TRIGGERS = [
        'always' => 'Always (within the dates)',
        'hours_left_more' => 'While more than N hours are left before closing',
        'hours_left_at_most' => 'When N hours or fewer are left before closing',
        'sold_pct_below' => 'While under N% of tickets are sold',
        'sold_pct_at_least' => 'Once N% or more of tickets are sold',
        'sold_24h_at_least' => 'While N or more tickets sold in the last 24 hours (demand)',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'bundle_tier_ids' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $r) {
            if (! array_key_exists($r->trigger, self::TRIGGERS)) {
                throw new \DomainException('Unknown trigger.');
            }
            if ($r->trigger !== 'always' && $r->trigger_value === null) {
                throw new \DomainException('This trigger needs a number.');
            }
            if (($r->bonus_buy === null) !== ($r->bonus_get === null) || ($r->bonus_buy !== null && ($r->bonus_buy < 1 || $r->bonus_get < 1))) {
                throw new \DomainException('Bonus tickets need both "buy" and "get free", each 1 or more.');
            }
            if ($r->price_pence !== null && $r->price_pence < 1) {
                throw new \DomainException('A price must be at least 1p.');
            }
            if ($r->release_pct !== null && ($r->release_pct < 1 || $r->release_pct > 100)) {
                throw new \DomainException('Tickets on sale must be 1% to 100%.');
            }
            if ($r->price_pence === null && $r->bonus_buy === null && $r->bundle_tier_ids === null && $r->release_pct === null) {
                throw new \DomainException('A rule needs something to change: price, bonus tickets, bundles or tickets on sale.');
            }
        });
        $log = function (self $r, string $what) {
            if (auth()->user() && $r->competition?->verity_ref !== null) {
                AdminAction::record(auth()->user(), 'competition.price_rule_'.$what, (string) $r->competition_id, null,
                    ['rule' => $r->name] + array_intersect_key($r->getAttributes(), array_flip(['trigger', 'trigger_value', 'price_pence', 'bonus_buy', 'bonus_get', 'release_pct', 'is_active'])));
            }
        };
        static::created(fn (self $r) => $log($r, 'added'));
        static::updated(fn (self $r) => $log($r, 'changed'));
        static::deleted(fn (self $r) => $log($r, 'removed'));
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }
}

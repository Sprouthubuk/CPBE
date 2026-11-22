<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A loyalty tier, reached by lifetime points earned; earns at multiplier_pct (150 = 1.5x). */
class LoyaltyTier extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (LoyaltyTier $t) {
            if ($t->multiplier_pct < 100 || $t->multiplier_pct > 1000) {
                throw new \DomainException('A tier earns between 1x and 10x (100% to 1000%).');
            }
        });
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgramme::class, 'loyalty_programme_id');
    }
}

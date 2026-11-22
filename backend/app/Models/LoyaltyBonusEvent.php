<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bonus points: a flat amount on sign-up, a birthday or a first order, or a higher earn rate on one
 * competition (multiplier_pct, 200 = double points), optionally between two dates.
 */
class LoyaltyBonusEvent extends Model
{
    public const KINDS = ['signup' => 'Sign-up', 'birthday' => 'Birthday', 'first_order' => 'First order', 'competition' => 'A competition'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (LoyaltyBonusEvent $e) {
            if (! isset(self::KINDS[$e->kind])) {
                throw new \DomainException('Unknown bonus event.');
            }
            if ($e->kind === 'competition' && ! $e->competition_id) {
                throw new \DomainException('Choose the competition for this bonus.');
            }
        });
    }

    public function isOn(): bool
    {
        return $this->active && (! $this->starts_at || $this->starts_at->isPast()) && (! $this->ends_at || $this->ends_at->isFuture());
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgramme::class, 'loyalty_programme_id');
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }
}

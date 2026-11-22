<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A loyalty programme (SOW 2.11). The Client can run several and switch each on or off. */
class LoyaltyProgramme extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(LoyaltyTier::class)->orderBy('threshold_points');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(LoyaltyReward::class)->orderBy('points_cost');
    }

    public function bonusEvents(): HasMany
    {
        return $this->hasMany(LoyaltyBonusEvent::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reward a player bought with points. Custom rewards wait as "pending" until the Client fulfils them. */
class LoyaltyRedemption extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fulfilled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'loyalty_reward_id');
    }
}

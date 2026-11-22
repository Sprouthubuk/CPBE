<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reward a referral paid: to the friend (level 0), the referrer (1) or someone further up (2+). */
class ReferralReward extends Model
{
    protected $guarded = ['id'];

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's membership. pending (first payment not made) → active ↔ past_due (a renewal failed: perks
 * paused, retried) → cancelled (by the player at the period end, or at once by self-exclusion or a break)
 * | lapsed (renewals kept failing). Changed only by App\Services\Memberships.
 */
class Membership extends Model
{
    public const CURRENT = ['active', 'past_due'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'current_period_start' => 'datetime', 'current_period_end' => 'datetime',
            'next_attempt_at' => 'datetime', 'ended_at' => 'datetime', 'cancel_at_period_end' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class, 'membership_tier_id');
    }

    public function pendingTier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class, 'pending_tier_id');
    }

    /** Perks apply only while paid up. */
    public function perksOn(): bool
    {
        return $this->status === 'active' && $this->current_period_end?->isFuture();
    }
}

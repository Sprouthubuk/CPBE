<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Voluntary Code 1.6: a sign of possible harm for a person to review (App\Services\HarmSignals). */
class HarmFlag extends Model
{
    public const INDICATORS = [
        'limit_reached' => 'Reached a spending limit',
        'limit_raised' => 'Asked to raise or remove a limit',
        'spend_30d' => 'High spending in 30 days',
        'top_ups_24h' => 'Many top-ups in 24 hours',
        'cards_30d' => 'Many different cards in 30 days',
        'returned_from_break' => 'Spending again soon after a break',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

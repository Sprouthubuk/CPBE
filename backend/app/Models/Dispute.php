<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A chargeback or payment dispute logged against an order (SOW 2.15). Lost chargebacks reverse referral and loyalty rewards. */
class Dispute extends Model
{
    public const KINDS = ['chargeback' => 'Chargeback', 'dispute' => 'Dispute / inquiry', 'retrieval' => 'Retrieval request'];

    public const STATUSES = ['open' => 'Open', 'evidence_sent' => 'Evidence sent', 'won' => 'Won', 'lost' => 'Lost', 'accepted' => 'Accepted'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['respond_by' => 'date', 'closed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}

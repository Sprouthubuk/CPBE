<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUlids;

    public const UNPAID = ['created', 'pending_payment'];

    /** How long an unpaid order holds its tickets while the player is at the payment page. */
    public const HOLD_MINUTES = 15;

    public const CLOSED = ['failed', 'cancelled', 'expired'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'needs_attention' => 'boolean',
            'credit_allowed' => 'boolean',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The IP an order was placed from, for chargeback evidence (SOW 2.15).
        static::creating(function (Order $o) {
            if ($o->ip === null && request()->route() !== null && ($ip = request()->ip())) {
                $o->ip = $ip;
            }
            // A/B tests (SOW 2.18): the consenting visitor's id, so their tests can count this order.
            if ($o->visitor_id === null && request()->route() !== null && request()->cookie('cp_consent') === 'accepted'
                && is_string($vid = request()->cookie('cp_vid')) && preg_match('/^[a-f0-9]{16,40}$/', $vid)) {
                $o->visitor_id = $vid;
            }
            // The marketing channel (SOW 2.14), only set for visitors who accepted cookies (frontend consent.ts).
            if ($o->channel === null && request()->route() !== null && request()->cookie('cp_consent') === 'accepted'
                && is_string($src = request()->cookie('cp_src')) && preg_match('/^[a-z0-9_-]{1,40}$/', $src)) {
                $o->channel = $src;
            }
        });
        // A refunded ticket order may have qualified a referral or earned points: take back what it unlocked.
        static::updated(function (Order $o) {
            if ($o->wasChanged('status') && $o->status === 'refunded' && $o->order_type === 'tickets') {
                app(\App\Services\Referrals::class)->orderReversed($o);
                app(\App\Services\Loyalty::class)->orderReversed($o);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function isDeposit(): bool
    {
        return $this->order_type === 'deposit';
    }

    public static function newNumber(string $prefix): string
    {
        do {
            $n = $prefix.'-'.strtoupper(substr(str_replace(['0', 'O', '1', 'I'], '', bin2hex(random_bytes(8))), 0, 8));
        } while (static::query()->where('number', $n)->exists() || strlen($n) < 11);

        return $n;
    }
}

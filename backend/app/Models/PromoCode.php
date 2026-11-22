<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A discount code (SOW 2.3): a percentage or a fixed amount off, with a total use limit, a per-player limit,
 * start and expiry dates and a minimum basket spend. By default it applies to single tickets only; a code can
 * be set to include bundles. Whatever the settings, a code takes at most half of what it applies to, so it
 * never makes an order free. Priced only in App\Services\Pricing.
 */
class PromoCode extends Model
{
    public const MAX_PERCENT = 50;

    public const TYPES = ['percent' => 'Percentage off', 'fixed' => 'Fixed amount off'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'starts_at' => 'datetime', 'active' => 'boolean', 'include_bundles' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (PromoCode $p) {
            $p->discount_type = $p->discount_type ?: 'percent';
            if (! isset(self::TYPES[$p->discount_type])) {
                throw new \DomainException('A promo code is a percentage or a fixed amount off.');
            }
            if ($p->discount_type === 'percent') {
                if ($p->percent_off < 1 || $p->percent_off > self::MAX_PERCENT) {
                    throw new \DomainException('A promo code takes between 1% and '.self::MAX_PERCENT.'% off.');
                }
                $p->amount_off_pence = null;
            } else {
                if ((int) $p->amount_off_pence < 1) {
                    throw new \DomainException('A fixed promo code takes at least 1p off.');
                }
                $p->percent_off = null;
            }
            if ($p->starts_at && $p->expires_at && $p->expires_at->lte($p->starts_at)) {
                throw new \DomainException('A promo code must end after it starts.');
            }
            $p->code = strtoupper(trim($p->code));
        });
    }

    /** Pence off an amount it applies to (at most half of it). */
    public function discountOn(int $eligiblePence): int
    {
        $off = $this->discount_type === 'fixed'
            ? (int) $this->amount_off_pence
            : intdiv($eligiblePence * min((int) $this->percent_off, self::MAX_PERCENT), 100);

        return max(0, min($off, intdiv($eligiblePence, 2)));
    }

    /** "20% off" or "£5.00 off". */
    public function label(): string
    {
        return $this->discount_type === 'fixed'
            ? '£'.number_format(((int) $this->amount_off_pence) / 100, 2).' off'
            : $this->percent_off.'% off';
    }

    /** How many of this player's orders have used the code (paid, or still waiting for payment). */
    public function usesBy(User $user): int
    {
        return Order::query()->where('user_id', $user->id)->where('promo_code', $this->code)
            ->whereNotIn('status', Order::CLOSED)->count();
    }

    protected $table = 'promo_codes';

    protected $guarded = ['id'];
}

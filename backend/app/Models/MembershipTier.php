<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A monthly membership tier (SOW 2.12): price, who can join, and its perks. */
class MembershipTier extends Model
{
    public const ELIGIBILITY = ['anyone' => 'Anyone', 'id_verified' => 'ID-checked players', 'min_spend' => 'Players who have spent at least…'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (MembershipTier $t) {
            if ($t->price_pence < 100) {
                throw new \DomainException('A membership costs at least £1 a month.');
            }
            if (! isset(self::ELIGIBILITY[$t->eligibility ?: 'anyone'])) {
                throw new \DomainException('Unknown eligibility rule.');
            }
            if ($t->ticket_discount_pct < 0 || $t->ticket_discount_pct > 90) {
                throw new \DomainException('A ticket discount is 0% to 90%.');
            }
            if ($t->loyalty_bonus_pct > 900) {
                throw new \DomainException('The loyalty bonus is at most +900%.');
            }
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** Perks in words, for the player and the admin. @return list<string> */
    public function perkList(): array
    {
        return array_values(array_filter([
            $this->monthly_free_tickets ? "{$this->monthly_free_tickets} free entries every month" : null,
            $this->monthly_bonus_credit_pence ? '£'.number_format($this->monthly_bonus_credit_pence / 100, 2).' bonus credit every month' : null,
            $this->early_access_hours ? "Enter new competitions {$this->early_access_hours} hours early" : null,
            $this->loyalty_bonus_pct ? "+{$this->loyalty_bonus_pct}% loyalty points" : null,
            $this->ticket_discount_pct ? "{$this->ticket_discount_pct}% off tickets" : null,
            $this->id && Competition::query()->published()->where('closes_at', '>', now())->whereJsonContains('members_only_tier_ids', $this->id)->exists() ? 'Members-only competitions' : null,
            ...array_filter(array_map('trim', preg_split('/\R/', (string) $this->perks))),
        ]));
    }
}

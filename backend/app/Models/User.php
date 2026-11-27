<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, MustVerifyEmailTrait, Notifiable;

    // Two-step sign-in for the admin panel (authenticator app + recovery codes), stored encrypted and never sent to the site.
    use InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    /** Fields a player fills once; after that only an admin changes them. */
    public const FILL_ONCE = ['first_name', 'last_name', 'date_of_birth', 'address_line1', 'address_line2', 'city', 'postcode'];

    protected $guarded = ['id', 'is_admin', 'is_staff', 'on_hold_at', 'closed_at', 'id_verified_at', 'email_verified_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'date_of_birth' => 'date',
            'marketing_email' => 'boolean',
            'marketing_sms' => 'boolean',
            'name_hidden' => 'boolean',
            'marketing_email_consent_at' => 'datetime',
            'marketing_sms_consent_at' => 'datetime',
            'win_notifications' => 'boolean',
            'is_admin' => 'boolean',
            'is_staff' => 'boolean',
            'id_verified_at' => 'datetime',
            'on_hold_at' => 'datetime',
            'closed_at' => 'datetime',
            'cool_off_until' => 'datetime',
            'limits_prompted_at' => 'datetime',
            'self_excluded_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Customer accounts are never deleted: their money, entries and wins hang off them.
        static::deleting(fn () => throw new \DomainException('Accounts are closed, never deleted.'));
        // SOW 3.3: the email and SMS platforms follow each player's consent and details.
        static::created(function (User $u) {
            if (! $u->is_admin && ($u->marketing_email || $u->marketing_sms)) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\MarketingSync::class)->contact($u));
            }
        });
        static::updated(function (User $u) {
            if (! $u->is_admin && $u->wasChanged(['marketing_email', 'marketing_sms', 'first_name', 'last_name', 'phone', 'email', 'self_excluded_until'])) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\MarketingSync::class)->contact($u));
            }
        });
        // Self-exclusion, a break, a hold or a closed account ends a membership at once: no more charges (SOW 2.8, 2.12).
        static::updated(function (User $u) {
            foreach (['self_excluded_until' => 'self-excluded', 'cool_off_until' => 'took a break', 'on_hold_at' => 'account on hold', 'closed_at' => 'account closed'] as $col => $why) {
                if ($u->wasChanged($col) && $u->$col !== null && (! $u->$col instanceof \DateTimeInterface || $u->$col > now())) {
                    app(\App\Services\Memberships::class)->stopFor($u, $why);

                    return;
                }
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin && $this->closed_at === null && $this->hasVerifiedEmail();
    }

    /**
     * The marketing choices with the date each was agreed, ready to save: a choice turned on now gets
     * today's date, one left on keeps its date, one turned off loses it.
     *
     * @return array<string, mixed>
     */
    public function marketingChoices(bool $email, bool $sms): array
    {
        return [
            'marketing_email' => $email, 'marketing_email_consent_at' => $email ? ($this->marketing_email ? $this->marketing_email_consent_at : now()) : null,
            'marketing_sms' => $sms, 'marketing_sms_consent_at' => $sms ? ($this->marketing_sms ? $this->marketing_sms_consent_at : now()) : null,
        ];
    }

    public function getFilamentName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function ownerRef(): string
    {
        return 'user:'.$this->id;
    }

    /** "Liam O." — the only form of a player's name ever shown publicly. */
    /** "Liam O.", or "Anonymous" for a player who asked not to be named (Terms 8.2). */
    public function publicName(): string
    {
        if ($this->name_hidden) {
            return 'Anonymous';
        }

        return trim($this->first_name.' '.mb_substr((string) $this->last_name, 0, 1).'.');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function limits(): HasOne
    {
        return $this->hasOne(SpendLimit::class);
    }
}

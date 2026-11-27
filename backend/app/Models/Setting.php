<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Site settings an admin can change. Read through Settings::get so every page agrees. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Defaults: the safe value when a setting has never been saved. Sales start switched off. */
    public const DEFAULTS = [
        'sales_open' => '0',
        'min_deposit_pence' => '500',
        'min_card_pence' => '100',
        'min_withdrawal_pence' => '2000',
        'max_top_up_pence' => '50000',
        'bonus_expiry_days' => '30',
        'featured_slug' => null,
        'instagram_url' => null,
        'facebook_url' => null,
        'whatsapp_url' => null,
        'tiktok_url' => null,
        'support_email' => null,
        // Vault Case (off until switched on in Admin → Settings)
        'feature_vault_case' => '0',
        'vault_case_chips' => '500,1000,2000,5000',
        'vault_case_min_pence' => '500',
        'vault_case_max_pence' => '10000',
        'vault_case_bonus_pct' => '10',
        'vault_case_max_sold_pct' => '95',
        'vault_case_slow_boost_pct' => '150',
        'vault_case_min_draws' => '2',
        'vault_case_max_draws' => '4',
        'vault_case_hold_minutes' => '10',
        'vault_case_share_of_sales_pct' => '30',
        // Refer a Friend (off until switched on)
        'feature_referrals' => '0',
        // Voluntary Code 1.3 (amendment 11): credit card payments a player may make a month, on draws only.
        'credit_card_monthly_cap_pence' => '25000',
        // Amendment 8: shown on every draw unless the competition says otherwise.
        'draw_watch_default' => 'Streamed live: the link is posted on our social pages before the draw.',
        'winner_announce_default' => 'We contact the winner by email and phone, then show their first name and initial on our Winners page.',
        // Voluntary Code 1.10: an instant win can't be published if instant wins would be the majority running.
        'code_instant_majority_guard' => '1',
        // Voluntary Code 1.6: harm indicators (App\Services\HarmSignals).
        'harm_spend_30d_pence' => '100000',
        'harm_top_ups_24h' => '5',
        'harm_cards_30d' => '3',
        // Reports (SOW 2.14): Cashflows' starting rate, 2% + 20p.
        'payment_fee_bp' => '200',
        'payment_fee_fixed_pence' => '20',
        'monthly_revenue_target_pence' => '0',
        'promo_budget_pct' => '0',
        // Amendment 3: VAT included in ticket prices, if the business is VAT registered (0 = none).
        'vat_pct' => '0',
        'referral_reward_type' => 'tickets',
        'referral_reward_tickets' => '10',
        'referral_reward_credit_pence' => '200',
        'referral_min_purchase_pence' => '500',
        'referral_monthly_cap' => '10',
        'referral_voucher_days' => '30',
        'referral_clawback_days' => '14',
        'referral_flag_after' => '3',
    ];

    public static function get(string $key): ?string
    {
        $all = Cache::remember('settings:all', 30, fn () => static::query()->pluck('value', 'key')->all());

        return array_key_exists($key, $all) ? $all[$key] : (self::DEFAULTS[$key] ?? null);
    }

    public static function int(string $key): int
    {
        return (int) static::get($key);
    }

    public static function bool(string $key): bool
    {
        return static::get($key) === '1';
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings:all');
    }
}

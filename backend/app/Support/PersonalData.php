<?php

namespace App\Support;

use App\Models\ContactMessage;
use App\Models\IdCheck;
use App\Models\LedgerEntry;
use App\Models\LoyaltyEntry;
use App\Models\Membership;
use App\Models\Order;
use App\Models\PostalEntry;
use App\Models\PrizeClaim;
use App\Models\Referral;
use App\Models\SpendLimit;
use App\Models\User;
use App\Models\Withdrawal;

/**
 * Everything we hold about one player, for a subject access request (UK GDPR Article 15). An admin downloads it
 * from Players → "Export personal data" and sends it to the player. Secrets (password hash, 2FA) are left out;
 * ID documents are listed, not attached: send the files from Admin → ID Checks if they are asked for.
 */
final class PersonalData
{
    private const SECRET = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    /** @return array<string, mixed> */
    public static function export(User $u): array
    {
        $rows = fn ($query) => $query->get()->map(fn ($m) => $m->attributesToArray())->all();

        return [
            'exported_at' => now()->toIso8601String(),
            'account' => array_diff_key($u->getAttributes(), array_flip(self::SECRET)),
            'orders' => Order::query()->where('user_id', $u->id)->with('lines')->orderBy('id')->get()
                ->map(fn (Order $o) => $o->attributesToArray() + ['lines' => $o->lines->map->attributesToArray()->all()])->all(),
            'wallet_ledger' => $rows(LedgerEntry::query()->where('user_id', $u->id)->orderBy('id')),
            'withdrawals' => $rows(Withdrawal::query()->where('user_id', $u->id)->orderBy('id')),
            'postal_entries' => $rows(PostalEntry::query()->where('user_id', $u->id)->orderBy('id')),
            'id_checks' => IdCheck::query()->where('user_id', $u->id)->orderBy('id')->get()
                ->map(fn ($c) => array_diff_key($c->attributesToArray(), array_flip(['photo_path', 'address_path'])) + ['documents_held' => array_values(array_filter([$c->photo_path ? 'photo ID' : null, $c->address_path ? 'proof of address' : null]))])->all(),
            'prize_claims' => $rows(PrizeClaim::query()->where('user_id', $u->id)->orderBy('id')),
            'spend_limits' => $rows(SpendLimit::query()->where('user_id', $u->id)->orderBy('id')),
            'loyalty_points' => $rows(LoyaltyEntry::query()->where('user_id', $u->id)->orderBy('id')),
            'memberships' => $rows(Membership::query()->where('user_id', $u->id)->orderBy('id')),
            'referrals' => $rows(Referral::query()->where(fn ($query) => $query->where('referrer_id', $u->id)->orWhere('friend_id', $u->id))->orderBy('id')),
            'contact_messages' => $rows(ContactMessage::query()->where('email', $u->email)->orderBy('id')),
        ];
    }
}

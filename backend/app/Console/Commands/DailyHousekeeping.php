<?php

namespace App\Console\Commands;

use App\Models\LedgerEntry;
use App\Models\SpendLimit;
use App\Services\Wallet;
use Illuminate\Console\Command;

/** Apply limit raises whose 24 hours are up, write off expired bonus credit and vouchers, give birthday points and expire old points. */
class DailyHousekeeping extends Command
{
    protected $signature = 'players:housekeeping';

    protected $description = 'Apply waiting limit changes and expire bonus credit';

    public function handle(Wallet $wallet): int
    {
        $applied = 0;
        SpendLimit::query()->whereNotNull('pending_applies_at')->where('pending_applies_at', '<=', now())->get()->each(function (SpendLimit $l) use (&$applied) {
            $weeklyWaiting = $l->pending_remove || $l->pending_weekly_deposit_pence !== null;
            $fill = ['pending_weekly_deposit_pence' => null, 'pending_remove' => false, 'pending_applies_at' => null, 'pending_limits' => null];
            if ($weeklyWaiting) {
                $fill['weekly_deposit_pence'] = $l->pending_remove ? null : $l->pending_weekly_deposit_pence;
            }
            foreach ($l->pending_limits ?? [] as $period => $pence) {
                $fill["{$period}_deposit_pence"] = $pence;
            }
            $l->forceFill($fill)->save();
            $applied++;
        });
        $expired = 0;
        LedgerEntry::query()->where('bucket', 'bonus')->where('direction', 'credit')->where('expires_at', '<=', now())
            ->distinct()->pluck('user_id')->each(function ($id) use ($wallet, &$expired) {
                $expired += $wallet->expireBonus($id);
            });
        $vouchers = app(\App\Services\Referrals::class)->expireVouchers();
        app(\App\Services\VaultCases::class)->expire();
        $loyalty = app(\App\Services\Loyalty::class);
        $birthdays = $loyalty->birthdays();
        $pointsExpired = $loyalty->expire();
        $this->info("limits applied {$applied}, bonus expired {$expired}p, vouchers expired {$vouchers}, birthday bonuses {$birthdays}, points expiries {$pointsExpired}");

        return self::SUCCESS;
    }
}

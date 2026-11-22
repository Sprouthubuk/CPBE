<?php

namespace App\Console\Commands;

use App\Services\Memberships;
use Illuminate\Console\Command;

/** Renew memberships whose month is up, retry failed renewals and end cancelled ones (SOW 2.12). */
class RenewMemberships extends Command
{
    protected $signature = 'platform:memberships';

    protected $description = 'Renew, retry and end memberships';

    public function handle(Memberships $memberships): int
    {
        [$renewed, $failed, $ended] = $memberships->renewDue();
        $this->info("renewed {$renewed}, failed {$failed}, ended {$ended}");

        return self::SUCCESS;
    }
}

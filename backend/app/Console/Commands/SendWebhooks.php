<?php

namespace App\Console\Commands;

use App\Services\Webhooks;
use Illuminate\Console\Command;

class SendWebhooks extends Command
{
    protected $signature = 'platform:webhooks';

    protected $description = 'Send queued webhook events to the Client\'s tools (amendment 2)';

    public function handle(Webhooks $webhooks): int
    {
        [$sent, $failed] = $webhooks->send();
        if ($sent || $failed) {
            $this->info("{$sent} sent, {$failed} to retry.");
        }

        return self::SUCCESS;
    }
}

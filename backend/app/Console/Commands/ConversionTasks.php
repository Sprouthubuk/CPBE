<?php

namespace App\Console\Commands;

use App\Models\CostEntry;
use App\Services\Conversion;
use App\Services\MarketingSync;
use App\Services\Segments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * SOW 2.18 / 3.3. --recover (every 15 minutes): abandoned checkout reminders. --segments (nightly): segment
 * tags to the email platform. --ad-spend (nightly): yesterday's Meta ad spend into Costs, when configured.
 */
class ConversionTasks extends Command
{
    protected $signature = 'platform:conversion {--recover} {--segments} {--ad-spend}';

    protected $description = 'Checkout reminders, marketing segment sync and ad spend import';

    public function handle(Conversion $conversion, MarketingSync $sync, Segments $segments): int
    {
        if ($this->option('recover')) {
            $this->info('reminders '.$conversion->recoverAbandoned());
        }
        if ($this->option('segments') && $sync->enabled()) {
            $tags = [];
            foreach (array_keys(Segments::SEGMENTS) as $s) {
                $segments->query($s)->pluck('id')->each(function ($id) use (&$tags, $s) {
                    $tags[$id][] = $s;
                });
            }
            foreach (\App\Models\User::query()->whereIn('id', array_keys($tags))->get() as $u) {
                $sync->contact($u, $tags[$u->id]);
            }
            $this->info('segments synced for '.count($tags).' players');
        }
        if ($this->option('ad-spend')) {
            $this->info('ad spend days '.$this->metaSpend());
        }

        return self::SUCCESS;
    }

    /** Meta Marketing API insights: spend per day for the last 7 days, upserted as actual marketing costs. */
    private function metaSpend(): int
    {
        $account = (string) config('services.meta.ad_account_id');
        $token = (string) config('services.meta.marketing_token');
        if ($account === '' || $token === '') {
            return 0;
        }
        $res = Http::timeout(20)->get('https://graph.facebook.com/'.config('services.meta.graph_version').'/act_'.preg_replace('/\D/', '', $account).'/insights', [
            'fields' => 'spend', 'time_increment' => 1, 'access_token' => $token,
            'time_range' => json_encode(['since' => now('Europe/London')->subDays(7)->toDateString(), 'until' => now('Europe/London')->subDay()->toDateString()]),
        ]);
        if (! $res->successful()) {
            $this->warn('Meta did not return ad spend.');

            return 0;
        }
        $n = 0;
        foreach ((array) $res->json('data') as $row) {
            if (! isset($row['date_start'], $row['spend'])) {
                continue;
            }
            $pence = (int) round(((float) $row['spend']) * 100);
            $existing = CostEntry::query()->whereDate('date', $row['date_start'])->where(['category' => 'marketing', 'channel' => 'meta', 'note' => 'Meta Ads (imported)'])->first();
            $existing ? $existing->forceFill(['amount_pence' => $pence])->save()
                : CostEntry::query()->create(['date' => $row['date_start'], 'category' => 'marketing', 'channel' => 'meta', 'note' => 'Meta Ads (imported)', 'amount_pence' => $pence, 'basis' => 'actual']);
            $n++;
        }

        return $n;
    }
}

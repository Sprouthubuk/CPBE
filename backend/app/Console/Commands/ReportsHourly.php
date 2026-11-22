<?php

namespace App\Console\Commands;

use App\Models\ReportSchedule;
use App\Notifications\ReportSummary;
use App\Services\Alerts;
use App\Services\Reports;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/** Hourly: check the alert rules, and send scheduled summary reports when they're due (7am UK). */
class ReportsHourly extends Command
{
    protected $signature = 'platform:reports';

    protected $description = 'Check alerts and send scheduled reports';

    public function handle(Alerts $alerts, Reports $reports): int
    {
        $fired = $alerts->check();
        $sent = 0;
        $uk = now('Europe/London');
        foreach (ReportSchedule::query()->where('active', true)->get() as $s) {
            $due = $uk->hour >= 7 && match ($s->frequency) {
                'daily' => true, 'weekly' => $uk->isMonday(), 'monthly' => $uk->day === 1, default => false,
            } && (! $s->last_sent_at || $s->last_sent_at->copy()->timezone('Europe/London')->toDateString() !== $uk->toDateString());
            if (! $due) {
                continue;
            }
            $period = ['daily' => 'today', 'weekly' => 'week', 'monthly' => 'month'][$s->frequency];
            // The report covers the finished period before today.
            $to = $uk->copy()->startOfDay()->utc();
            $from = match ($s->frequency) { 'daily' => $to->copy()->subDay(), 'weekly' => $to->copy()->subWeek(), default => $to->copy()->subMonth() };
            $c = $reports->company($from, $to);
            $m = fn (int $p) => ($p < 0 ? '−' : '').'£'.number_format(abs($p) / 100, 2);
            $rows = [['Gross sales', $m($c['grossSales'])], ['Net revenue', $m($c['netRevenue'])], ['Costs', $m($c['totalCosts'])], ['Profit', $m($c['profit'])],
                ['Margin', $c['marginPct'] !== null ? $c['marginPct'].'%' : '—'], ['Orders', (string) $c['orders']], ['Paid entries', number_format($c['entries']['Paid'])]];
            Notification::route('mail', $s->email)->notify(new ReportSummary(ucfirst($s->frequency).' Report', $rows,
                $from->copy()->timezone('Europe/London')->format('j M').' to '.$to->copy()->subSecond()->timezone('Europe/London')->format('j M Y')));
            $s->forceFill(['last_sent_at' => now()])->save();
            $sent++;
            unset($period);
        }
        $this->info('alerts '.count($fired).", reports {$sent}");

        return self::SUCCESS;
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Pages\BusinessReports;
use App\Filament\Pages\ProfitPlanner as PlannerPage;
use App\Filament\Pages\Reconciliation;
use App\Models\AlertRule;
use App\Models\CostEntry;
use App\Models\Order;
use App\Models\ReportSchedule;
use App\Models\SettlementLine;
use App\Models\User;
use App\Notifications\ReportSummary;
use App\Services\ProfitPlanner;
use App\Services\Reports;
use App\Services\Segments;
use App\Services\Settlements;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** SOW 2.14: reporting and business intelligence. */
class ReportsTest extends TestCase
{
    private function sale(User $u, $c, int $tickets, string $payWith = 'wallet', ?string $channel = null): Order
    {
        if ($payWith === 'wallet') {
            $this->topUp($u, $tickets * $c->price_pence);
        }
        $r = $this->actingAs($u)->withCredentials()->withUnencryptedCookies(array_filter(['cp_consent' => $channel ? 'accepted' : null, 'cp_src' => $channel]))
            ->postJson('/api/checkout', ['lines' => [['competitionId' => $c->id, 'quantity' => $tickets]], 'payWith' => $payWith, 'useWallet' => $payWith === 'wallet', 'requestId' => (string) Str::uuid()])
            ->assertCreated();
        $o = Order::query()->where('number', $r->json('order.number'))->firstOrFail();
        if ($payWith === 'card') {
            $this->gateway()->settle($o->gateway_ref, 'paid');
            $this->actingAs($u)->postJson("/api/orders/{$o->number}/return", [])->assertOk();
        }

        return $o->refresh();
    }

    private function adminIn(string $role = 'admin'): User
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $a = User::factory()->admin()->create();
        $a->forceFill(['admin_role' => $role])->save();
        $a->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $this->actingAs($a->refresh());

        return $a;
    }

    public function test_company_figures_revenue_costs_profit_and_entries(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $this->sale($this->player(), $c, 10, 'card');
        $this->sale($this->player(), $c, 5);
        CostEntry::query()->create(['date' => now('Europe/London')->toDateString(), 'category' => 'marketing', 'amount_pence' => 300, 'channel' => 'meta']);
        $r = app(Reports::class);
        [$f, $t] = $r->range('month');
        $co = $r->company($f, $t);
        $this->assertSame(1500, $co['grossSales']);
        $this->assertSame(1500, $co['netRevenue']);
        $this->assertSame(15, $co['entries']['Paid']);
        $this->assertSame(20 + 20, $co['costs']['Payment fees (estimated)'], '2% of £10 + 20p');
        $this->assertSame(300, $co['costs']['Marketing / ad spend (entered)']);
        $this->assertSame(1500 - 340, $co['profit']);
        $this->assertSame(1500, $r->headline()['today']);
    }

    public function test_competition_forecasts_break_even_and_risk(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000, 'prize_cost_pence' => 50000, 'closes_at' => now()->addHours(10)]);
        $this->sale($this->player(), $c, 24);
        $row = app(Reports::class)->competitions()->firstWhere('id', $c->id);
        $this->assertSame(500, $row['breakEvenTickets']);
        $this->assertEqualsWithDelta(1.0, $row['perHour'], 0.01);
        $this->assertContains($row['forecastSold'], [33, 34], '24 sold + about 1 an hour for the 10 hours left');
        $this->assertTrue($row['atRisk']);
        $this->assertSame(2400, $row['revenue']);
    }

    public function test_customers_marketing_channels_and_cohorts(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $a = $this->player();
        $this->sale($a, $c, 2, 'wallet', 'meta');
        $this->sale($a, $c, 3, 'wallet', 'meta');
        $b = $this->player();
        $this->sale($b, $c, 5, 'wallet', 'google');
        CostEntry::query()->create(['date' => now('Europe/London')->toDateString(), 'category' => 'marketing', 'amount_pence' => 250, 'channel' => 'meta']);
        $this->assertSame('meta', Order::query()->where('user_id', $a->id)->value('channel'));
        $r = app(Reports::class);
        [$f, $t, $pf, $pt] = $r->range('week');
        $cu = $r->customers($f, $t, $pf, $pt);
        $this->assertSame(2, $cu['active']);
        $this->assertSame(1, $cu['repeat']);
        $this->assertSame(333, $cu['aov']);
        $mk = collect($r->marketing($f, $t)['rows'])->keyBy('channel');
        $this->assertSame(500, $mk['meta']['revenue']);
        $this->assertSame(1, $mk['meta']['newCustomers']);
        $this->assertSame(2.0, $mk['meta']['roas']);
        $this->assertSame(250, $mk['meta']['cpa']);
        $this->assertNotEmpty($r->cohorts(2));
    }

    public function test_the_profit_planner(): void
    {
        $m = ProfitPlanner::model(['prizeCost' => 50000, 'ticketPrice' => 100, 'maxTickets' => 1000, 'freeSharePct' => 0, 'promoSharePct' => 0,
            'discountPct' => 0, 'marketing' => 0, 'vatPct' => 0, 'feeBp' => 0, 'feeFixed' => 0]);
        $this->assertSame(500, $m['breakEvenTickets']);
        $this->assertSame(50.0, $m['breakEvenPct']);
        $this->assertSame(50000, $m['fullProfit']);
        $this->assertSame(['tickets' => 625, 'pct' => 62.5], $m['for20']);
        $this->assertSame(-40000, collect($m['levels'])->firstWhere('pct', 10)['profit']);
    }

    public function test_settlements_match_by_reference_and_amount(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $o = $this->sale($this->player(), $c, 10, 'card');
        $p = $this->sale($this->player(), $c, 5, 'card');
        $csv = "Payment Job Reference,Amount,Settlement Date\n{$o->gateway_ref},10.00,22/11/2026\n{$p->gateway_ref},4.00,22/11/2026\nNOPE-1,3.00,22/11/2026\n";
        $r = app(Settlements::class)->import($csv);
        $this->assertSame(3, $r['lines']);
        $this->assertSame(1, $r['matched']);
        $this->assertSame(['matched', 'amount', 'unmatched'], SettlementLine::query()->orderBy('id')->pluck('match')->all());
    }

    public function test_segments_include_only_opted_in_players_who_can_play(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $yes = $this->player(['marketing_email' => true]);
        $no = $this->player(['marketing_email' => false]);
        $excluded = $this->player(['marketing_email' => true]);
        $excluded->forceFill(['self_excluded_until' => now()->addYear()])->save();
        $this->sale($yes, $c, 2);
        $this->sale($no, $c, 2);
        $ids = app(Segments::class)->query('active')->pluck('id')->all();
        $this->assertSame([$yes->id], $ids);
        $this->assertSame($yes->email, app(Segments::class)->rows('all')[1][0]);
    }

    public function test_alerts_fire_once_a_day(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $this->sale($this->player(), $c, 10);
        CostEntry::query()->create(['date' => now('Europe/London')->toDateString(), 'category' => 'marketing', 'amount_pence' => 900]);
        config(['app.alert_emails' => 'boss@example.com']);
        Notification::fake();
        AlertRule::query()->create(['kind' => 'marketing_cost_high', 'threshold' => 50]);
        $this->artisan('platform:reports')->assertSuccessful();
        Notification::assertSentOnDemandTimes(\App\Notifications\AdminAlert::class, 1);
        $this->artisan('platform:reports')->assertSuccessful();
        Notification::assertSentOnDemandTimes(\App\Notifications\AdminAlert::class, 1);
    }

    public function test_scheduled_reports_go_out_once_when_due(): void
    {
        Notification::fake();
        ReportSchedule::query()->create(['email' => 'owner@example.com', 'frequency' => 'daily']);
        Carbon::setTestNow(now('Europe/London')->startOfDay()->addHours(8)->utc());
        $this->artisan('platform:reports')->assertSuccessful();
        $this->artisan('platform:reports')->assertSuccessful();
        Notification::assertSentOnDemandTimes(ReportSummary::class, 1);
    }

    public function test_the_dashboard_tabs_exports_and_print_page_work_for_the_reports_role_only(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $this->sale($this->player(), $c, 3);
        $this->adminIn('finance');
        $this->get('/admin/business')->assertOk()->assertSee('Company');
        foreach (['competitions', 'customers', 'marketing', 'operations', 'detail'] as $tab) {
            Livewire::test(BusinessReports::class)->set('tab', $tab)->assertOk();
        }
        Livewire::test(BusinessReports::class)->set('period', 'year')->set('trend', 'hour')->assertOk();
        $this->get('/admin/reports/export/orders.csv?period=month')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $x = $this->get('/admin/reports/export/competitions.xlsx?period=month')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('Content-Type'));
        $this->get('/admin/reports/export/segments.csv?segment=all')->assertOk();
        $this->get('/admin/reports/print?period=week')->assertOk()->assertSee('Business Report');
        $this->get('/admin/profit-planner')->assertOk();
        Livewire::test(PlannerPage::class)->call('addOption')->set('options.0.ticketPrice', '2.00')->assertOk()->assertSee('Break-even');
        $this->get('/admin/reconciliation')->assertOk();
        Livewire::test(Reconciliation::class)->set('file', UploadedFile::fake()->createWithContent('s.csv', "Order Number,Amount\nCP-NONE,1.00\n"))->call('import');
        $this->assertSame(1, SettlementLine::query()->count());
        foreach (['/admin/reports-admin/cost-entries', '/admin/reports-admin/alert-rules', '/admin/reports-admin/report-schedules'] as $p) {
            $this->get($p)->assertOk();
        }

        $this->adminIn('support');
        $this->get('/admin/business')->assertForbidden();
        $this->get('/admin/reports/export/orders.csv')->assertForbidden();
    }
}

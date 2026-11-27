<?php

namespace Tests\Feature;

use App\Filament\Resources\Integrations\Pages\ListApiTokens;
use App\Filament\Resources\Integrations\Pages\ListWebhookEndpoints;
use App\Models\ApiToken;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Postal;
use App\Services\Webhooks;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Amendment 2: API and webhook access to the Platform and the Client's data. */
class PlatformApiTest extends TestCase
{
    private function token(): string
    {
        return ApiToken::issue('Accounts', $this->admin())[1];
    }

    private function sale(User $u, $c, int $tickets): Order
    {
        $this->topUp($u, $tickets * $c->price_pence);
        $this->app['auth']->forgetGuards();
        $r = $this->actingAs($u, 'sanctum')->postJson('/api/checkout', ['lines' => [['competitionId' => $c->id, 'quantity' => $tickets]], 'payWith' => 'wallet', 'useWallet' => true, 'requestId' => (string) Str::uuid()])->assertCreated();
        $this->app['auth']->forgetGuards();

        return Order::query()->where('number', $r->json('order.number'))->firstOrFail();
    }

    public function test_the_read_only_api_needs_a_live_token_and_returns_the_clients_data(): void
    {
        $c = $this->competition('draw', ['price_pence' => 150, 'max_tickets' => 500, 'max_per_person' => 500]);
        $u = $this->player();
        $o = $this->sale($u, $c, 4);

        $this->getJson('/api/v1/orders')->assertUnauthorized()->assertJsonPath('code', 'api_token');
        $this->withToken('cpk_nonsense')->getJson('/api/v1/orders')->assertUnauthorized();

        $plain = $this->token();
        $this->assertNull(ApiToken::query()->where('token_hash', $plain)->first(), 'only the hash is stored');
        $orders = $this->withToken($plain)->getJson('/api/v1/orders')->assertOk();
        $row = collect($orders->json('data'))->firstWhere('number', $o->number);
        $this->assertSame(['paid', 600, 4], [$row['status'], $row['totalPence'], $row['lines'][0]['quantity']]);
        $this->assertNotNull(ApiToken::query()->sole()->last_used_at);

        $players = $this->withToken($plain)->getJson('/api/v1/players')->assertOk()->json('data');
        $this->assertSame([$u->email], array_column($players, 'email'), 'players only, never admins');
        $this->assertArrayNotHasKey('password', $players[0]);
        $this->assertArrayNotHasKey('dateOfBirth', $players[0]);

        $this->withToken($plain)->getJson('/api/v1/competitions')->assertOk()->assertJsonPath('data.0.pricePence', 150);
        $this->withToken($plain)->getJson('/api/v1/orders?since='.urlencode(now()->addMinute()->toIso8601ZuluString()))->assertOk()->assertJsonPath('total', 0);
        $this->withToken($plain)->getJson('/api/v1/orders?since=nonsense')->assertStatus(422);
        $days = $this->withToken($plain)->getJson('/api/v1/daily-totals?from='.now('Europe/London')->toDateString())->assertOk()->json('data');
        $this->assertSame(600, $days[0]['salesPence']);
        $this->withToken($plain)->getJson('/api/v1/daily-totals?from=2020-01-01&to=2026-01-01')->assertStatus(422);
        $this->withToken($plain)->postJson('/api/v1/orders', [])->assertStatus(405);

        ApiToken::query()->sole()->forceFill(['revoked_at' => now()])->save();
        $this->withToken($plain)->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_events_are_queued_for_subscribed_endpoints_and_sent_signed_with_retries(): void
    {
        $hook = WebhookEndpoint::query()->create(['name' => 'CRM', 'url' => 'https://crm.example.com/hook', 'events' => ['order.paid', 'player.registered', 'postal.entered']]);
        WebhookEndpoint::query()->create(['name' => 'Off', 'url' => 'https://off.example.com/hook', 'events' => ['order.paid'], 'active' => false]);
        $c = $this->competition('draw');
        $u = $this->player();
        $o = $this->sale($u, $c, 2);
        app(Postal::class)->log($c, $u->email, now()->subHour(), $this->admin());

        $this->assertSame(['player.registered', 'order.paid', 'postal.entered'], WebhookDelivery::query()->orderBy('id')->pluck('event')->all());
        $this->assertSame($o->number, WebhookDelivery::query()->where('event', 'order.paid')->sole()->payload['number']);

        Http::fake(['crm.example.com/*' => Http::sequence()->push('', 500)->push('', 200)->push('', 200)->push('', 200)]);
        $this->assertSame([2, 1], app(Webhooks::class)->send());
        $failed = WebhookDelivery::query()->whereNull('delivered_at')->sole();
        $this->assertSame([1, 500], [$failed->attempts, $failed->last_status]);
        $this->assertTrue($failed->next_attempt_at->isFuture());
        Http::assertSent(function (HttpRequest $r) use ($hook) {
            return $r->hasHeader('X-Signature', Webhooks::sign($r->body(), $hook->secret)) && json_decode($r->body(), true)['event'] !== null;
        });

        $this->travel(2)->minutes();
        $this->assertSame([1, 0], app(Webhooks::class)->send());
        $this->assertSame(0, WebhookDelivery::query()->whereNull('delivered_at')->count());
    }

    public function test_a_delivery_gives_up_after_eight_tries_and_addresses_must_be_https(): void
    {
        $hook = WebhookEndpoint::query()->create(['name' => 'BI', 'url' => 'https://bi.example.com/in', 'events' => ['order.paid']]);
        $hook->deliveries()->create(['event' => 'order.paid', 'payload' => [], 'next_attempt_at' => now()]);
        Http::fake(['*' => Http::response('', 503)]);
        for ($i = 0; $i < 8; $i++) {
            $this->travel(1)->days();
            app(Webhooks::class)->send();
        }
        $d = WebhookDelivery::query()->sole();
        $this->assertSame([8, null, null], [$d->attempts, $d->next_attempt_at, $d->delivered_at]);

        $this->expectException(\DomainException::class);
        WebhookEndpoint::query()->create(['name' => 'Plain', 'url' => 'http://bi.example.com/in', 'events' => ['order.paid']]);
    }

    public function test_only_the_admin_role_manages_tokens_and_webhooks_and_the_token_is_shown_once(): void
    {
        Filament::setCurrentPanel('admin');
        foreach (['admin' => 200, 'finance' => 403] as $role => $status) {
            $this->app['auth']->forgetGuards();
            $a = User::factory()->admin()->create(['admin_role' => $role]);
            $a->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
            $this->actingAs($a->refresh());
            $this->get('/admin/api-tokens')->assertStatus($status);
            $this->get('/admin/webhooks')->assertStatus($status);
        }
        $this->app['auth']->forgetGuards();
        $admin = User::factory()->admin()->create(['admin_role' => 'admin']);
        $this->actingAs($admin);
        $page = Livewire::test(ListApiTokens::class)->callAction('new', ['name' => 'Xero'])->assertHasNoActionErrors();
        $plain = $page->get('newToken');
        $this->assertStringStartsWith('cpk_', $plain);
        $t = ApiToken::query()->sole();
        $this->assertSame([hash('sha256', $plain), 'Xero'], [$t->token_hash, $t->name]);
        Livewire::test(ListApiTokens::class)->callTableAction('revoke', $t);
        $this->assertNotNull($t->refresh()->revoked_at);
        $this->assertSame(2, \App\Models\AdminAction::query()->whereIn('action', ['api_token.created', 'api_token.revoked'])->count());
        Livewire::test(ListWebhookEndpoints::class)->assertOk();
    }

    public function test_fraud_alerts_spot_shared_devices_shared_cards_and_declines(): void
    {
        foreach (['shared_device' => 2, 'shared_card' => 1, 'failed_payments' => 2] as $kind => $t) {
            \App\Models\AlertRule::query()->create(['kind' => $kind, 'threshold' => $t]);
        }
        $alerts = app(\App\Services\Alerts::class);
        $eval = fn (string $kind) => $alerts->evaluate(\App\Models\AlertRule::query()->where('kind', $kind)->sole());
        foreach (range(1, 3) as $i) {
            $this->player(['signup_device_hash' => str_repeat('d', 64)]);
        }
        $this->assertCount(1, $eval('shared_device'));
        $c = $this->competition('draw', ['max_per_person' => 1000]);
        foreach ([$this->player(), $this->player()] as $u) {
            $this->sale($u, $c, 1)->forceFill(['card_hash' => str_repeat('c', 64)])->saveQuietly();
        }
        $this->assertStringContainsString('2 accounts', $eval('shared_card')[0]);
        $this->assertSame([], $eval('failed_payments'));
        foreach (range(1, 3) as $i) {
            Order::query()->create(['number' => 'CP-F'.$i, 'user_id' => $this->player()->id, 'order_type' => 'tickets', 'status' => 'failed', 'total_pence' => 100, 'card_pence' => 100, 'wallet_pence' => 0]);
        }
        $this->assertCount(1, $eval('failed_payments'));
    }

    public function test_accounting_exports_daily_totals_and_journal(): void
    {
        $c = $this->competition('draw', ['price_pence' => 200, 'max_per_person' => 1000]);
        $this->sale($this->player(), $c, 3);
        $this->app['auth']->forgetGuards();
        $a = User::factory()->admin()->create(['admin_role' => 'finance']);
        $a->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $this->actingAs($a->refresh());
        $csv = $this->get('/admin/reports/export/daily.csv?period=month')->assertOk()->streamedContent();
        $this->assertStringContainsString('Sales (entries and memberships)', $csv);
        $journal = $this->get('/admin/reports/export/journal.csv?period=month')->assertOk()->streamedContent();
        $this->assertStringContainsString('Date,Reference,Description,Account,Debit,Credit', str_replace('"', '', $journal));
        $rows = app(\App\Services\Reports::class)->journal(now()->startOfDay(), now());
        $debits = array_sum(array_map(fn ($r) => (float) $r[4], array_slice($rows, 1)));
        $credits = array_sum(array_map(fn ($r) => (float) $r[5], array_slice($rows, 1)));
        $this->assertEqualsWithDelta($debits, $credits, 0.001, 'each journal balances');
    }
}

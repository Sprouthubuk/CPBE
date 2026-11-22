<?php

namespace Tests\Feature;

use App\Models\CostEntry;
use App\Models\Experiment;
use App\Models\ExperimentAssignment;
use App\Models\Order;
use App\Models\User;
use App\Notifications\AbandonedCheckout;
use App\Services\Conversion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/** SOW 2.18 checkout and conversion tools; SOW 3.3 marketing platforms and Google / Weglot settings. */
class ConversionTest extends TestCase
{
    private function buy(User $u, $c, int $qty, array $extra = [], array $cookies = [])
    {
        $this->topUp($u, $qty * $c->price_pence);

        return $this->actingAs($u)->withCredentials()->withUnencryptedCookies($cookies)->postJson('/api/checkout', [
            'lines' => [['competitionId' => $c->id, 'quantity' => $qty] + $extra], 'payWith' => 'wallet', 'useWallet' => true, 'requestId' => (string) Str::uuid(),
        ])->assertCreated();
    }

    public function test_recommendations_follow_what_the_player_plays(): void
    {
        $instant = $this->competition('instant', ['price_pence' => 50, 'tags' => ['cash']]);
        $other = $this->competition('instant', ['price_pence' => 50, 'tags' => ['cash']]);
        $draw = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000, 'tags' => ['tech']]);
        $u = $this->player();
        $this->buy($u, $instant, 2);
        $ids = array_column($this->actingAs($u)->getJson('/api/recommendations?exclude='.$instant->id.'&limit=2')->assertOk()->json('data'), 'id');
        $this->assertSame($other->id, $ids[0], 'the same kind and category first');
        $this->assertNotContains($instant->id, $ids);
        $this->assertCount(3, $this->getJson('/api/recommendations')->json('data'));
        $this->assertStringNotContainsString('skill_answer', $this->getJson('/api/recommendations')->getContent());
        unset($draw);
    }

    public function test_buy_again_gives_the_basket_lines_still_on_sale(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $u = $this->player();
        $n = $this->buy($u, $c, 3)->json('order.number');
        $r = $this->actingAs($u)->getJson("/api/orders/{$n}/repeat")->assertOk()->json();
        $this->assertSame([['competitionId' => $c->id, 'quantity' => 3, 'via' => 'repeat']], $r['lines']);
        $this->actingAs($this->player())->getJson("/api/orders/{$n}/repeat")->assertNotFound();
    }

    public function test_lines_record_which_tool_brought_them(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $u = $this->player();
        $this->buy($u, $c, 2, ['via' => 'upsell']);
        $this->assertSame('upsell', Order::query()->firstOrFail()->lines()->value('via'));
        $report = app(Conversion::class)->toolReport(now()->subDay(), now()->addMinute());
        $this->assertSame([['via' => 'upsell', 'orders' => 1, 'revenue' => 200]], $report);
        $this->actingAs($u)->postJson('/api/checkout', ['lines' => [['competitionId' => $c->id, 'quantity' => 1, 'via' => 'hack']], 'payWith' => 'wallet',
            'useWallet' => true, 'requestId' => (string) Str::uuid()])->assertStatus(422);
    }

    public function test_one_checkout_reminder_only_to_opted_in_players_who_didnt_pay(): void
    {
        Notification::fake();
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $make = function (bool $optIn) use ($c) {
            $u = $this->player(['marketing_email' => $optIn]);
            $this->actingAs($u)->postJson('/api/checkout', ['lines' => [['competitionId' => $c->id, 'quantity' => 5]], 'payWith' => 'card', 'requestId' => (string) Str::uuid()])->assertCreated();

            return $u;
        };
        $yes = $make(true);
        $no = $make(false);
        $paid = $make(true);
        Carbon::setTestNow(now()->addMinutes(30));
        $this->buy($paid, $c, 1); // bought something since
        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('platform:conversion --recover')->assertSuccessful();
        Notification::assertSentTo($yes, AbandonedCheckout::class);
        Notification::assertNotSentTo($no, AbandonedCheckout::class);
        Notification::assertNotSentTo($paid, AbandonedCheckout::class);
        $this->artisan('platform:conversion --recover')->assertSuccessful();
        Notification::assertSentToTimes($yes, AbandonedCheckout::class, 1);
    }

    public function test_ab_tests_assign_steadily_count_views_and_purchases(): void
    {
        $e = Experiment::query()->create(['key' => 'home_hero_cta', 'name' => 'CTA', 'active' => true,
            'variants' => [['key' => 'control', 'weight' => 50, 'text' => null], ['key' => 'b', 'weight' => 50, 'text' => 'Play Now']]]);
        // Visitors who haven't accepted cookies don't take part.
        $this->assertSame([], $this->getJson('/api/experiments')->json('data'));
        $u = $this->player();
        $first = $this->actingAs($u)->getJson('/api/experiments')->json('data.home_hero_cta.variant');
        $this->assertSame($first, $this->actingAs($u)->getJson('/api/experiments')->json('data.home_hero_cta.variant'));
        $this->actingAs($u)->postJson('/api/experiments/home_hero_cta/seen')->assertOk();
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $this->buy($u, $c, 4);
        $a = ExperimentAssignment::query()->where('subject', 'u:'.$u->id)->firstOrFail();
        $this->assertNotNull($a->converted_at);
        $this->assertSame(400, $a->revenue_pence);
        $this->assertStringContainsString('1 bought', \App\Filament\Resources\Experiments\ExperimentResource::results($e));

        // A consenting visitor is identified by cp_vid.
        $vid = str_repeat('ab', 10);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $v = $this->withCredentials()->withUnencryptedCookies(['cp_consent' => 'accepted', 'cp_vid' => $vid])->getJson('/api/experiments')->json('data');
        $this->assertArrayHasKey('home_hero_cta', $v);
        $this->assertTrue(ExperimentAssignment::query()->where('subject', 'v:'.$vid)->exists());
    }

    public function test_the_email_platform_gets_consent_and_purchases_and_failures_never_break_the_site(): void
    {
        config(['services.marketing.email_driver' => 'webhook', 'services.marketing.webhook_url' => 'https://hooks.example.com/x', 'services.marketing.webhook_secret' => 'shh']);
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true])]);
        $u = $this->player(['marketing_email' => true]);
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $this->buy($u, $c, 2);
        Http::assertSent(fn ($r) => $r['type'] === 'contact' && $r['data']['email'] === $u->email && $r['data']['emailConsent'] === true
            && $r->header('X-Signature')[0] === hash_hmac('sha256', $r->body(), 'shh'));
        Http::assertSent(fn ($r) => $r['type'] === 'event' && $r['data']['name'] === 'Placed Order' && $r['data']['properties']['value'] === 2);
        // Not opted in: no events.
        $quiet = $this->player(['marketing_email' => false]);
        Http::fake(['hooks.example.com/*' => Http::response('down', 500)]);
        $this->buy($quiet, $c, 1);
        // A failing platform doesn't stop a purchase.
        $this->buy($u, $c, 1);
    }

    public function test_mailchimp_and_klaviyo_requests(): void
    {
        Http::fake();
        config(['services.marketing.email_driver' => 'mailchimp', 'services.marketing.email_key' => 'abc-us21', 'services.marketing.email_list' => 'L1']);
        $u = $this->player(['marketing_email' => true, 'email' => 'Mixed@Example.com']);
        app(\App\Services\MarketingSync::class)->contact($u->refresh(), ['members']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->url() === 'https://us21.api.mailchimp.com/3.0/lists/L1/members/'.md5('mixed@example.com') && $r['status'] === 'subscribed');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/tags') && $r['tags'][0]['name'] === 'members');
        config(['services.marketing.email_driver' => 'klaviyo', 'services.marketing.email_key' => 'pk_x']);
        app(\App\Services\MarketingSync::class)->event($u, 'Abandoned Checkout', ['order' => 'CP-1', 'value' => 5]);
        Http::assertSent(fn ($r) => $r->url() === 'https://a.klaviyo.com/api/events/' && $r->header('Authorization')[0] === 'Klaviyo-API-Key pk_x'
            && $r['data']['attributes']['metric']['data']['attributes']['name'] === 'Abandoned Checkout');
    }

    public function test_sms_opt_outs_are_signed_and_stop_texts(): void
    {
        config(['services.marketing.sms_optout_secret' => 'k']);
        $u = $this->player(['marketing_sms' => true, 'phone' => '07700 900123']);
        $body = json_encode(['phone' => '+447700900123']);
        $this->call('POST', '/api/webhooks/sms-opt-out', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'bad'], $body)->assertForbidden();
        $this->call('POST', '/api/webhooks/sms-opt-out', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'k')], $body)
            ->assertOk()->assertJsonPath('optedOut', 1);
        $this->assertFalse($u->refresh()->marketing_sms);
    }

    public function test_meta_ad_spend_is_imported_as_marketing_costs(): void
    {
        config(['services.meta.ad_account_id' => 'act_123', 'services.meta.marketing_token' => 't']);
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [['date_start' => '2026-11-20', 'spend' => '12.34'], ['date_start' => '2026-11-21', 'spend' => '5']]])]);
        $this->artisan('platform:conversion --ad-spend')->assertSuccessful();
        $this->artisan('platform:conversion --ad-spend')->assertSuccessful();
        $this->assertSame([1234, 500], CostEntry::query()->orderBy('date')->pluck('amount_pence')->all());
    }

    public function test_google_and_weglot_settings_reach_the_site_only_when_valid(): void
    {
        \App\Models\Setting::put('google_ga4_id', 'G-ABC123XYZ');
        \App\Models\Setting::put('google_ads_id', 'not-an-id');
        \App\Models\Setting::put('weglot_api_key', 'wg_0123456789abcdef');
        $s = $this->getJson('/api/site')->json();
        $this->assertSame('G-ABC123XYZ', $s['google']['ga4Id']);
        $this->assertNull($s['google']['adsId']);
        $this->assertSame(['weglotKey' => 'wg_0123456789abcdef'], $s['translation']);
        \App\Models\Setting::put('google_ga4_id', null);
        \App\Models\Setting::put('weglot_api_key', 'bad');
        $s = $this->getJson('/api/site')->json();
        $this->assertNull($s['google']);
        $this->assertNull($s['translation']);
    }
}

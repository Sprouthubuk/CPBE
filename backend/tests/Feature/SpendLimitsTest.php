<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** SOW 2.8: daily, weekly and monthly deposit limits, enforced at checkout; raising waits 24 hours. */
class SpendLimitsTest extends TestCase
{
    private function payByCard($u, $c, int $tickets)
    {
        return $this->actingAs($u)->postJson('/api/checkout', ['lines' => [['competitionId' => $c->id, 'quantity' => $tickets]], 'payWith' => 'card',
            'requestId' => (string) Str::uuid()]);
    }

    public function test_each_limit_stops_a_card_payment_that_would_pass_it(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 10000, 'max_per_person' => 10000]);
        $u = $this->player();
        $this->actingAs($u)->postJson('/api/me/limits', ['dailyDepositPence' => 1000, 'monthlyDepositPence' => 5000])->assertOk()
            ->assertJsonPath('dailyDepositPence', 1000)->assertJsonPath('monthlyDepositPence', 5000)->assertJsonPath('weeklyDepositPence', null);
        $this->payByCard($u, $c, 11)->assertStatus(403)->assertJsonPath('code', 'daily_limit');
        $o = $this->payByCard($u, $c, 10)->assertCreated()->json('order.number');
        $this->gateway()->settle(\App\Models\Order::query()->where('number', $o)->value('gateway_ref'), 'paid');
        $this->actingAs($u)->postJson("/api/orders/{$o}/return", [])->assertOk();
        $this->payByCard($u, $c, 1)->assertStatus(403)->assertJsonPath('code', 'daily_limit');

        // Next day the daily limit has room, but the monthly one counts the last 30 days.
        $this->actingAs($u)->postJson('/api/me/limits', ['monthlyDepositPence' => 1500])->assertOk();
        Carbon::setTestNow(now()->addDays(2));
        $this->payByCard($u, $c, 6)->assertStatus(403)->assertJsonPath('code', 'monthly_limit');
        $this->payByCard($u, $c, 5)->assertCreated();
    }

    public function test_raising_or_removing_waits_24_hours_and_lowering_is_instant(): void
    {
        $u = $this->player();
        $this->actingAs($u)->postJson('/api/me/limits', ['dailyDepositPence' => 1000])->assertOk();
        $r = $this->actingAs($u)->postJson('/api/me/limits', ['dailyDepositPence' => 5000])->assertOk();
        $this->assertSame(1000, $r->json('dailyDepositPence'));
        $this->assertSame(5000, $r->json('pending.dailyDepositPence'));
        $this->assertArrayNotHasKey('weeklyDepositPence', $r->json('pending'));
        $this->actingAs($u)->postJson('/api/me/limits', ['monthlyDepositPence' => 2000])->assertOk()->assertJsonPath('monthlyDepositPence', 2000);
        Carbon::setTestNow(now()->addHours(25));
        $this->artisan('players:housekeeping')->assertSuccessful();
        $l = $this->actingAs($u->refresh())->getJson('/api/me/limits')->json();
        $this->assertSame(5000, $l['dailyDepositPence']);
        $this->assertSame(2000, $l['monthlyDepositPence']);
        $this->assertNull($l['pending']);
    }

    public function test_the_old_weekly_only_form_still_works(): void
    {
        $u = $this->player();
        $this->actingAs($u)->postJson('/api/me/limits', ['weeklyDepositPence' => 2500])->assertOk()->assertJsonPath('weeklyDepositPence', 2500);
        $this->actingAs($u)->postJson('/api/me/limits', ['weeklyDepositPence' => 400])->assertStatus(422);
    }
}

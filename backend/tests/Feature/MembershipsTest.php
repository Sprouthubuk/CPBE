<?php

namespace Tests\Feature;

use App\Models\LoyaltyProgramme;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Payments\FakeGateway;
use App\Services\Loyalty;
use App\Services\Memberships;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** SOW 2.12: monthly membership tiers, perks, recurring billing, upgrades, downgrades and cancelling. */
class MembershipsTest extends TestCase
{
    private function tier(array $attrs = []): MembershipTier
    {
        return MembershipTier::query()->create($attrs + ['name' => 'Silver', 'price_pence' => 999, 'active' => true,
            'monthly_free_tickets' => 5, 'monthly_bonus_credit_pence' => 200]);
    }

    private function memberships(): Memberships
    {
        return app(Memberships::class);
    }

    /** Join by card and pay on the (fake) payment page. */
    private function joinByCard(User $u, MembershipTier $t): Membership
    {
        $r = $this->actingAs($u)->postJson('/api/me/membership', ['tierId' => $t->id, 'payWith' => 'card'])->assertCreated();
        $this->assertNotNull($r->json('redirectUrl'));
        $order = Order::query()->where('number', $r->json('order.number'))->firstOrFail();
        $this->assertTrue($this->gateway()->job($order->gateway_ref)['recurringParent'], 'the first payment is a recurring parent');
        $this->gateway()->settle($order->gateway_ref, 'paid');
        $this->actingAs($u)->postJson("/api/orders/{$order->number}/return", [])->assertOk();

        return $this->memberships()->current($u);
    }

    public function test_joining_by_card_starts_the_month_and_gives_the_perks(): void
    {
        $t = $this->tier();
        $u = $this->player();
        $m = $this->joinByCard($u, $t);
        $this->assertSame('active', $m->status);
        $this->assertTrue($m->perksOn());
        $this->assertNotNull($m->gateway_parent_ref);
        $this->assertSame(200, $this->wallet()->balances($u)['bonus']);
        $v = Voucher::query()->where('user_id', $u->id)->where('source', 'membership')->firstOrFail();
        $this->assertSame(5, $v->tickets);
        $s = $this->actingAs($u)->getJson('/api/me/membership')->assertOk()->json('membership');
        $this->assertSame('Silver', $s['tier']['name']);
        $this->assertContains('5 free entries every month', $s['tier']['perks']);
        // One membership at a time.
        $this->actingAs($u)->postJson('/api/me/membership', ['tierId' => $t->id, 'payWith' => 'card'])->assertStatus(422)->assertJsonPath('code', 'membership_exists');
    }

    public function test_joining_from_the_wallet_uses_cash_never_bonus_credit(): void
    {
        $t = $this->tier();
        $u = $this->player();
        $this->topUp($u, 5000, 'bonus');
        $this->actingAs($u)->postJson('/api/me/membership', ['tierId' => $t->id, 'payWith' => 'wallet'])->assertStatus(422)->assertJsonPath('code', 'not_enough_wallet');
        $this->topUp($u, 999);
        $this->actingAs($u)->postJson('/api/me/membership', ['tierId' => $t->id, 'payWith' => 'wallet'])->assertCreated()->assertJsonPath('order.status', 'paid');
        $this->assertSame(0, $this->wallet()->balances($u)['deposit']);
        $this->assertSame('active', $this->memberships()->current($u)->status);
    }

    public function test_renewals_charge_the_stored_card_each_month_and_give_new_perks(): void
    {
        $t = $this->tier();
        $u = $this->player();
        $m = $this->joinByCard($u, $t);
        $end = $m->current_period_end;
        Carbon::setTestNow($end->copy()->addMinute());
        $this->artisan('platform:memberships')->assertSuccessful();
        $m->refresh();
        $this->assertSame('active', $m->status);
        $this->assertSame($end->timestamp, $m->current_period_start->timestamp);
        $renewal = Order::query()->where('membership_id', $m->id)->where('membership_kind', 'renewal')->firstOrFail();
        $this->assertSame('paid', $renewal->status);
        $this->assertSame(999, $renewal->card_pence);
        $this->assertSame(2, Voucher::query()->where('user_id', $u->id)->where('source', 'membership')->count());
        // Running it again in the same month does nothing.
        $this->artisan('platform:memberships')->assertSuccessful();
        $this->assertSame(1, Order::query()->where('membership_kind', 'renewal')->count());
    }

    public function test_a_failed_renewal_pauses_perks_retries_then_lapses(): void
    {
        $t = $this->tier();
        $u = $this->player();
        $m = $this->joinByCard($u, $t);
        FakeGateway::$recurringResult = 'failed';
        Carbon::setTestNow($m->current_period_end->copy()->addMinute());
        $this->artisan('platform:memberships');
        $m->refresh();
        $this->assertSame('past_due', $m->status);
        $this->assertFalse($m->perksOn());
        $this->assertNull($this->memberships()->perkTier($u));
        $this->assertSame('failed', Order::query()->where('membership_kind', 'renewal')->latest('created_at')->value('status'));

        // The next try works: perks back.
        FakeGateway::$recurringResult = 'paid';
        Carbon::setTestNow(now()->addDays(Memberships::RETRY_DAYS)->addMinute());
        $this->artisan('platform:memberships');
        $this->assertSame('active', $m->refresh()->status);

        // Three failures in a row and it lapses.
        FakeGateway::$recurringResult = 'failed';
        Carbon::setTestNow($m->current_period_end->copy()->addMinute());
        for ($i = 0; $i < Memberships::MAX_ATTEMPTS; $i++) {
            $this->artisan('platform:memberships');
            Carbon::setTestNow(now()->addDays(Memberships::RETRY_DAYS)->addMinute());
        }
        $this->assertSame('lapsed', $m->refresh()->status);
        $this->assertNull($this->memberships()->current($u));
    }

    public function test_a_failed_renewal_can_be_paid_now_on_a_new_card(): void
    {
        $t = $this->tier();
        $u = $this->player();
        $m = $this->joinByCard($u, $t);
        FakeGateway::$recurringResult = 'failed';
        Carbon::setTestNow($m->current_period_end->copy()->addMinute());
        $this->artisan('platform:memberships');
        $r = $this->actingAs($u)->postJson('/api/me/membership/pay')->assertCreated();
        $order = Order::query()->where('number', $r->json('order.number'))->firstOrFail();
        $this->gateway()->settle($order->gateway_ref, 'paid');
        $this->actingAs($u)->postJson("/api/orders/{$order->number}/return", [])->assertOk();
        $m->refresh();
        $this->assertSame('active', $m->status);
        $this->assertSame($order->gateway_ref, $m->gateway_parent_ref);
    }

    public function test_cancelling_ends_at_the_period_end_and_keeping_undoes_it(): void
    {
        $t = $this->tier();
        $u = $this->player();
        $m = $this->joinByCard($u, $t);
        $this->actingAs($u)->postJson('/api/me/membership/cancel')->assertOk()->assertJsonPath('membership.cancelAtPeriodEnd', true);
        $this->actingAs($u)->postJson('/api/me/membership/keep')->assertOk()->assertJsonPath('membership.cancelAtPeriodEnd', false);
        $this->actingAs($u)->postJson('/api/me/membership/cancel')->assertOk();
        $this->assertTrue($m->refresh()->perksOn(), 'perks carry on to the end of the month');
        Carbon::setTestNow($m->current_period_end->copy()->addMinute());
        $this->artisan('platform:memberships');
        $this->assertSame('cancelled', $m->refresh()->status);
        $this->assertSame(0, Order::query()->where('membership_kind', 'renewal')->count());
    }

    public function test_upgrading_charges_the_difference_for_the_rest_of_the_month_and_downgrading_waits(): void
    {
        $silver = $this->tier();
        $gold = $this->tier(['name' => 'Gold', 'price_pence' => 1999, 'monthly_free_tickets' => 20]);
        $u = $this->player();
        $m = $this->joinByCard($u, $silver);
        Carbon::setTestNow($m->current_period_start->copy()->addSeconds((int) ($m->current_period_start->diffInSeconds($m->current_period_end) / 2)));
        $r = $this->actingAs($u)->postJson('/api/me/membership/change', ['tierId' => $gold->id])->assertOk();
        $order = Order::query()->where('number', $r->json('order.number'))->firstOrFail();
        $this->assertSame('paid', $order->status);
        $this->assertEqualsWithDelta(500, $order->card_pence, 2);
        $this->assertSame('Gold', $this->memberships()->current($u)->tier->name);

        $this->actingAs($u)->postJson('/api/me/membership/change', ['tierId' => $silver->id])->assertOk()->assertJsonPath('membership.nextTier', 'Silver');
        Carbon::setTestNow($m->refresh()->current_period_end->copy()->addMinute());
        $this->artisan('platform:memberships');
        $m->refresh();
        $this->assertSame('Silver', $m->tier->name);
        $this->assertSame(999, Order::query()->where('membership_kind', 'renewal')->value('card_pence'));
    }

    public function test_self_exclusion_ends_the_membership_at_once(): void
    {
        $u = $this->player();
        $m = $this->joinByCard($u, $this->tier());
        $u->forceFill(['self_excluded_until' => now()->addMonths(6)])->save();
        $this->assertSame('cancelled', $m->refresh()->status);
        Carbon::setTestNow($m->current_period_end->copy()->addMinute());
        $this->artisan('platform:memberships');
        $this->assertSame(0, Order::query()->where('membership_kind', 'renewal')->count());
    }

    public function test_eligibility_and_capacity(): void
    {
        $idOnly = $this->tier(['name' => 'VIP', 'eligibility' => 'id_verified']);
        $big = $this->tier(['name' => 'High Roller', 'eligibility' => 'min_spend', 'min_spend_pence' => 10000]);
        $full = $this->tier(['name' => 'Founders', 'max_members' => 1]);
        $u = $this->player();
        $this->actingAs($u)->postJson('/api/me/membership', ['tierId' => $idOnly->id, 'payWith' => 'card'])->assertStatus(422)->assertJsonPath('code', 'membership_ineligible');
        $this->actingAs($u)->postJson('/api/me/membership', ['tierId' => $big->id, 'payWith' => 'card'])->assertStatus(422)->assertJsonPath('code', 'membership_ineligible');
        $this->joinByCard($this->player(), $full);
        $this->actingAs($u)->postJson('/api/me/membership', ['tierId' => $full->id, 'payWith' => 'card'])->assertStatus(422);
        $tiers = collect($this->actingAs($u)->getJson('/api/me/membership')->json('tiers'))->keyBy('name');
        $this->assertNotNull($tiers['VIP']['ineligible']);
        $this->assertNull($tiers['Silver']['ineligible'] ?? null);
    }

    public function test_members_earn_bonus_loyalty_points_and_enter_early(): void
    {
        $p = LoyaltyProgramme::query()->create(['name' => 'Club', 'active' => true, 'points_per_pound' => 10]);
        $t = $this->tier(['loyalty_bonus_pct' => 50, 'early_access_hours' => 24]);
        $u = $this->player();
        $this->joinByCard($u, $t);
        $soon = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000, 'opens_at' => now()->addHours(12)]);
        $this->topUp($u, 1000);
        $buy = fn (User $who) => $this->actingAs($who)->postJson('/api/checkout', ['lines' => [['competitionId' => $soon->id, 'quantity' => 2]],
            'payWith' => 'wallet', 'useWallet' => true, 'requestId' => (string) Str::uuid()]);
        $res = $buy($u);
        $this->assertSame(201, $res->status(), (string) $res->getContent());
        $this->assertSame(30, app(Loyalty::class)->balance($u->id, $p->id), '£2 × 10 points × 1.5');

        $other = $this->player();
        $this->topUp($other, 1000);
        $this->assertNotSame(201, $buy($other)->status(), 'non-members wait for it to open');
        $this->assertSame('scheduled', $this->actingAs($other)->getJson('/api/competitions/'.$soon->slug)->json('status'));
        $this->assertSame('live', $this->actingAs($u)->getJson('/api/competitions/'.$soon->slug)->json('status'));
    }

    public function test_the_site_says_when_memberships_are_open(): void
    {
        $this->assertFalse($this->getJson('/api/site')->json('memberships'));
        $this->tier();
        $this->assertTrue($this->getJson('/api/site')->json('memberships'));
    }

    public function test_the_admin_sets_up_tiers_and_can_end_a_membership(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->saveAppAuthenticationSecret(app(\Filament\Auth\MultiFactor\App\AppAuthentication::class)->generateSecret());
        $this->actingAs($admin->refresh());
        \Livewire\Livewire::test(\App\Filament\Resources\Memberships\Pages\CreateMembershipTier::class)->fillForm([
            'name' => 'Platinum', 'price_pence' => '29.99', 'active' => true, 'eligibility' => 'anyone',
            'monthly_free_tickets' => 50, 'monthly_bonus_credit_pence' => '10.00', 'early_access_hours' => 48, 'loyalty_bonus_pct' => 100,
        ])->call('create')->assertHasNoFormErrors();
        $t = MembershipTier::query()->where('name', 'Platinum')->firstOrFail();
        $this->assertSame(2999, $t->price_pence);
        $this->assertSame(1000, $t->monthly_bonus_credit_pence);
        $this->get('/admin/memberships/membership-tiers')->assertOk()->assertSee('Platinum');

        $u = $this->player();
        $order = $this->memberships()->join($u, $t, 'card');
        $this->gateway()->settle($order->gateway_ref, 'paid');
        app(\App\Services\Orders::class)->syncWithGateway($order);
        $m = $this->memberships()->current($u);
        $this->assertSame('active', $m->status);
        $this->get('/admin/memberships')->assertOk()->assertSee($u->email);
        \Livewire\Livewire::test(\App\Filament\Resources\Memberships\Pages\ListMemberships::class)
            ->callTableAction('end', $m, data: ['reason' => 'Asked by phone'])->assertHasNoTableActionErrors();
        $this->assertSame('cancelled', $m->refresh()->status);
    }
}

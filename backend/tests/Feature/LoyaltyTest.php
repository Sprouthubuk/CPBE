<?php

namespace Tests\Feature;

use App\Filament\Resources\Loyalty\Pages\CreateLoyaltyProgramme;
use App\Filament\Resources\Loyalty\Pages\ListLoyaltyRedemptions;
use App\Models\LoyaltyEntry;
use App\Models\LoyaltyProgramme;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Loyalty;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** SOW 2.11: loyalty programmes, earning on real money, tiers, bonus events, expiry and rewards. */
class LoyaltyTest extends TestCase
{
    private function programme(array $attrs = []): LoyaltyProgramme
    {
        return LoyaltyProgramme::query()->create($attrs + ['name' => 'Rewards Club', 'active' => true, 'points_per_pound' => 10]);
    }

    private function buy(User $u, $c, int $qty)
    {
        return $this->actingAs($u)->postJson('/api/checkout', ['lines' => [['competitionId' => $c->id, 'quantity' => $qty]], 'payWith' => 'wallet',
            'useWallet' => true, 'requestId' => (string) Str::uuid()])->assertCreated();
    }

    private function loyalty(): Loyalty
    {
        return app(Loyalty::class);
    }

    public function test_a_paid_order_earns_points_on_real_money_only_once(): void
    {
        $p = $this->programme();
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $u = $this->player();
        $this->topUp($u, 1000);
        $this->buy($u, $c, 5);
        $this->assertSame(50, $this->loyalty()->balance($u->id, $p->id)); // £5 × 10 points

        // Bonus credit earns nothing.
        $b = $this->player();
        $this->topUp($b, 1000, 'bonus');
        $this->topUp($b, 200);
        $this->buy($b, $c, 4); // deposit first (£2), then bonus for the rest (£2, at most half)
        $this->assertSame(20, $this->loyalty()->balance($b->id, $p->id));

        // Running the hook again for the same order pays nothing more.
        $this->loyalty()->orderPaid(Order::query()->where('user_id', $u->id)->firstOrFail());
        $this->assertSame(50, $this->loyalty()->balance($u->id, $p->id));
    }

    public function test_a_switched_off_programme_earns_nothing_and_isnt_shown(): void
    {
        $p = $this->programme(['active' => false]);
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $u = $this->player();
        $this->topUp($u, 1000);
        $this->buy($u, $c, 5);
        $this->assertSame(0, $this->loyalty()->balance($u->id, $p->id));
        $this->assertFalse($this->getJson('/api/site')->json('loyalty'));
        $this->assertSame([], $this->actingAs($u)->getJson('/api/me/loyalty')->json('programmes'));
    }

    public function test_tiers_multiply_points_and_competition_bonuses_apply_to_their_share(): void
    {
        $p = $this->programme(['points_per_pound' => 1]);
        $p->tiers()->create(['name' => 'Bronze', 'threshold_points' => 0, 'multiplier_pct' => 100]);
        $p->tiers()->create(['name' => 'Gold', 'threshold_points' => 10, 'multiplier_pct' => 200]);
        $double = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $p->bonusEvents()->create(['kind' => 'competition', 'competition_id' => $double->id, 'multiplier_pct' => 200]);
        $plain = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $u = $this->player();
        $this->topUp($u, 10000);

        $this->buy($u, $plain, 10); // Bronze: 10 points, now Gold
        $this->assertSame(10, $this->loyalty()->balance($u->id, $p->id));
        $this->assertSame('Gold', $this->loyalty()->tierFor($u->id, $p->fresh('tiers'))->name);
        $this->buy($u, $plain, 10); // Gold 2x: 20
        $this->buy($u, $double, 10); // Gold 2x × competition 2x: 40
        $this->assertSame(70, $this->loyalty()->balance($u->id, $p->id));
    }

    public function test_sign_up_first_order_and_birthday_bonuses_are_paid_once(): void
    {
        $p = $this->programme();
        $p->bonusEvents()->createMany([
            ['kind' => 'signup', 'points' => 100], ['kind' => 'first_order', 'points' => 50], ['kind' => 'birthday', 'points' => 25],
        ]);
        $email = 'new'.strtolower(Str::random(6)).'@example.com';
        $this->postJson('/api/auth/register', ['firstName' => 'Bea', 'lastName' => 'Day', 'email' => $email, 'password' => 'correct-horse-9',
            'dateOfBirth' => now('Europe/London')->subYears(30)->toDateString()])->assertCreated();
        $u = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame(100, $this->loyalty()->balance($u->id, $p->id));

        // Past the Voluntary Code's spend limit offer (CodeComplianceTest covers it).
        $u->forceFill(['email_verified_at' => now(), 'limits_prompted_at' => now()])->save();
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $this->topUp($u, 1000);
        $this->buy($u, $c, 1);
        $this->buy($u, $c, 1);
        $this->assertSame(100 + 50 + 20, $this->loyalty()->balance($u->id, $p->id));

        $this->assertGreaterThanOrEqual(1, $this->loyalty()->birthdays());
        $this->loyalty()->birthdays();
        $this->assertSame(195, $this->loyalty()->balance($u->id, $p->id));
    }

    public function test_a_refund_takes_the_points_back(): void
    {
        $p = $this->programme();
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $u = $this->player();
        $this->topUp($u, 1000);
        $this->buy($u, $c, 5);
        $order = Order::query()->where('user_id', $u->id)->firstOrFail();
        $order->forceFill(['status' => 'refunded'])->save();
        $this->assertSame(0, $this->loyalty()->balance($u->id, $p->id));
        $this->assertSame(0, $this->loyalty()->lifetime($u->id, $p->id));
    }

    public function test_points_expire_oldest_first_and_only_what_is_unspent(): void
    {
        $p = $this->programme(['expiry_days' => 30]);
        $u = $this->player();
        $admin = $this->admin();
        $this->loyalty()->adjust($admin, $u, $p, 100, 'Goodwill');
        $reward = $p->rewards()->create(['name' => '£0.50', 'type' => 'site_credit', 'points_cost' => 40, 'credit_pence' => 50]);
        $this->loyalty()->redeem($u, $reward);
        Carbon::setTestNow(now()->addDays(10));
        $this->loyalty()->adjust($admin, $u, $p, 30, 'Later');
        Carbon::setTestNow(now()->addDays(25)); // the first 100 have expired, 40 of them were spent
        $this->loyalty()->expire();
        $this->assertSame(30, $this->loyalty()->balance($u->id, $p->id));
        $this->loyalty()->expire(); // nothing more
        $this->assertSame(30, $this->loyalty()->balance($u->id, $p->id));
        Carbon::setTestNow(now()->addDays(10));
        $this->loyalty()->expire();
        $this->assertSame(0, $this->loyalty()->balance($u->id, $p->id));
    }

    public function test_rewards_pay_site_credit_vouchers_or_wait_for_the_client(): void
    {
        $p = $this->programme();
        $draw = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $other = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $credit = $p->rewards()->create(['name' => '£1 credit', 'type' => 'site_credit', 'points_cost' => 100, 'credit_pence' => 100]);
        $free = $p->rewards()->create(['name' => '3 free', 'type' => 'free_entries', 'points_cost' => 100, 'tickets' => 3]);
        $bonus = $p->rewards()->create(['name' => '2 bonus', 'type' => 'bonus_entries', 'points_cost' => 100, 'tickets' => 2, 'competition_id' => $draw->id]);
        $custom = $p->rewards()->create(['name' => 'Mug', 'type' => 'custom', 'points_cost' => 100, 'stock' => 1]);
        $u = $this->player();
        $this->loyalty()->adjust($this->admin(), $u, $p, 600, 'Test');

        $this->actingAs($u)->postJson('/api/me/loyalty/redeem', ['rewardId' => $credit->id])->assertCreated();
        $this->assertSame(100, $this->wallet()->balances($u)['bonus']);

        $v = $this->actingAs($u)->postJson('/api/me/loyalty/redeem', ['rewardId' => $free->id])->assertCreated()->json('redemption.voucherId');
        $this->assertSame('loyalty', Voucher::query()->find($v)->source);
        // Usable with referrals switched off; any live draw.
        $this->assertCount(2, $this->actingAs($u)->getJson("/api/me/vouchers/{$v}/draws")->assertOk()->json('data'));
        $this->actingAs($u)->postJson("/api/me/vouchers/{$v}/redeem", ['competitionId' => $draw->id])->assertCreated();

        $bv = $this->actingAs($u)->postJson('/api/me/loyalty/redeem', ['rewardId' => $bonus->id])->assertCreated()->json('redemption.voucherId');
        $this->assertSame([$draw->id], array_column($this->actingAs($u)->getJson("/api/me/vouchers/{$bv}/draws")->json('data'), 'id'));
        $this->actingAs($u)->postJson("/api/me/vouchers/{$bv}/redeem", ['competitionId' => $other->id])->assertStatus(422);

        $this->assertSame('pending', $this->actingAs($u)->postJson('/api/me/loyalty/redeem', ['rewardId' => $custom->id])->assertCreated()->json('redemption.status'));
        $this->actingAs($u)->postJson('/api/me/loyalty/redeem', ['rewardId' => $custom->id])->assertStatus(422)->assertJsonPath('code', 'reward_out_of_stock');
        $this->assertSame(200, $this->loyalty()->balance($u->id, $p->id));
        $this->actingAs($u)->postJson('/api/me/loyalty/redeem', ['rewardId' => $credit->id])->assertCreated();
        $this->actingAs($u)->postJson('/api/me/loyalty/redeem', ['rewardId' => $credit->id])->assertCreated();
        $this->actingAs($u)->postJson('/api/me/loyalty/redeem', ['rewardId' => $credit->id])->assertStatus(422)->assertJsonPath('code', 'not_enough_points');
    }

    public function test_a_reward_can_need_a_tier(): void
    {
        $p = $this->programme();
        $gold = $p->tiers()->create(['name' => 'Gold', 'threshold_points' => 1000, 'multiplier_pct' => 100]);
        $r = $p->rewards()->create(['name' => 'VIP', 'type' => 'custom', 'points_cost' => 10, 'min_tier_id' => $gold->id]);
        $u = $this->player();
        $this->loyalty()->adjust($this->admin(), $u, $p, 100, 'Test');
        $this->actingAs($u)->postJson('/api/me/loyalty/redeem', ['rewardId' => $r->id])->assertStatus(422)->assertJsonPath('code', 'reward_tier');
        $this->assertFalse($this->actingAs($u)->getJson('/api/me/loyalty')->json('programmes.0.rewards.0.available'));
    }

    public function test_a_self_excluded_player_cant_spend_points(): void
    {
        $p = $this->programme();
        $r = $p->rewards()->create(['name' => '£1', 'type' => 'site_credit', 'points_cost' => 10, 'credit_pence' => 100]);
        $u = $this->player();
        $this->loyalty()->adjust($this->admin(), $u, $p, 100, 'Test');
        $this->actingAs($u)->postJson('/api/me/self-exclude', ['months' => 6, 'confirm' => true]);
        $this->actingAs($u->refresh())->postJson('/api/me/loyalty/redeem', ['rewardId' => $r->id])->assertStatus(422);
        $this->assertSame(100, $this->loyalty()->balance($u->id, $p->id));
    }

    public function test_the_points_history_is_append_only(): void
    {
        $p = $this->programme();
        $u = $this->player();
        $this->loyalty()->adjust($this->admin(), $u, $p, 10, 'Test');
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('loyalty_entries')->update(['points' => 1000]);
    }

    public function test_the_player_summary_and_basket_estimate(): void
    {
        $p = $this->programme(['expiry_days' => 365]);
        $p->tiers()->create(['name' => 'Bronze', 'threshold_points' => 0, 'multiplier_pct' => 100]);
        $p->tiers()->create(['name' => 'Silver', 'threshold_points' => 500, 'multiplier_pct' => 150]);
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $u = $this->player();
        $this->topUp($u, 1000);
        $this->buy($u, $c, 5);
        $s = $this->actingAs($u)->getJson('/api/me/loyalty')->assertOk()->json('programmes.0');
        $this->assertSame(50, $s['balance']);
        $this->assertSame('Bronze', $s['tier']['name']);
        $this->assertSame(['name' => 'Silver', 'pointsToGo' => 450], $s['nextTier']);
        $this->assertSame('earn', $s['history'][0]['kind']);
        $this->assertNotNull($s['history'][0]['expiresAt']);
        $this->assertTrue($this->getJson('/api/site')->json('loyalty'));

        $priced = $this->actingAs($u)->postJson('/api/basket/price', ['lines' => [['competitionId' => $c->id, 'quantity' => 3]]])->json('loyaltyPoints');
        $this->assertSame([['programme' => 'Rewards Club', 'points' => 30]], $priced);
    }

    public function test_the_admin_sets_up_a_programme_and_handles_custom_rewards(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $this->actingAs($admin->refresh());
        Livewire::test(CreateLoyaltyProgramme::class)->fillForm([
            'name' => 'VIP Points', 'active' => true, 'points_per_pound' => 5, 'expiry_days' => 180,
        ])->call('create')->assertHasNoFormErrors();
        $p = LoyaltyProgramme::query()->where('name', 'VIP Points')->firstOrFail();
        $this->get('/admin/loyalty/loyalty-programmes')->assertOk()->assertSee('VIP Points');
        $this->get('/admin/loyalty/loyalty-programmes/'.$p->id.'/edit')->assertOk();

        $r = $p->rewards()->create(['name' => 'Hoodie', 'type' => 'custom', 'points_cost' => 10, 'stock' => 5]);
        $u = $this->player();
        $this->loyalty()->adjust($admin, $u, $p, 30, 'Test');
        $a = $this->loyalty()->redeem($u, $r);
        $b = $this->loyalty()->redeem($u, $r);
        $this->get('/admin/loyalty/loyalty-redemptions')->assertOk()->assertSee('Hoodie');
        Livewire::test(ListLoyaltyRedemptions::class)->callTableAction('fulfil', $a, data: ['note' => 'Posted'])->assertHasNoTableActionErrors();
        Livewire::test(ListLoyaltyRedemptions::class)->callTableAction('cancel', $b, data: ['reason' => 'Out of size'])->assertHasNoTableActionErrors();
        $this->assertSame('fulfilled', $a->refresh()->status);
        $this->assertSame('cancelled', $b->refresh()->status);
        $this->assertSame(20, $this->loyalty()->balance($u->id, $p->id));
        $this->assertSame(4, $r->refresh()->stock);
        $this->assertSame(30, $this->loyalty()->lifetime($u->id, $p->id), 'returned points never raise a tier');
        $this->assertSame(2, LoyaltyRedemption::query()->count());
        $this->assertTrue(LoyaltyEntry::query()->where('kind', 'refund')->exists());
        $this->assertInstanceOf(LoyaltyReward::class, $a->reward);
    }
}

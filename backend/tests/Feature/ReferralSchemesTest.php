<?php

namespace Tests\Feature;

use App\Filament\Resources\ReferralSchemes\Pages\CreateReferralScheme;
use App\Models\Competition;
use App\Models\LoyaltyProgramme;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\ReferralScheme;
use App\Models\Setting;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Loyalty;
use App\Services\Memberships;
use App\Services\Orders;
use App\Services\Referrals;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** SOW 2.13: referral schemes with eligibility, qualifying actions, rewards, caps, dates and levels. */
class ReferralSchemesTest extends TestCase
{
    private Competition $draw;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('feature_referrals', '1');
        Notification::fake();
        $this->draw = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 5000, 'max_per_person' => 5000, 'max_bundle_share_pct' => 10]);
    }

    private function scheme(array $attrs = []): ReferralScheme
    {
        return ReferralScheme::query()->create($attrs + ['name' => 'Launch Offer', 'active' => true, 'qualifying' => 'first_order', 'min_spend_pence' => 500,
            'friend_reward_type' => 'tickets', 'friend_reward_amount' => 5, 'referrer_reward_type' => 'credit', 'referrer_reward_amount' => 300]);
    }

    /** $friend is referred by $referrer (as if they signed up with the link), from its own device and IP. */
    private function refer(User $referrer, ?User $friend = null): User
    {
        $friend ??= $this->player();
        app(Referrals::class)->codeFor($referrer);
        app(Referrals::class)->attach($friend, $referrer->refresh()->referral_code, 'dev-'.Str::random(8), '10.0.'.random_int(1, 250).'.'.random_int(1, 250));

        return $friend->refresh();
    }

    private function buy(User $u, int $pence): Order
    {
        $this->topUp($u, $pence);

        return app(Orders::class)->checkout($u, [['competitionId' => $this->draw->id, 'quantity' => intdiv($pence, 100)]], 'wallet', true, null, (string) Str::uuid())['order'];
    }

    public function test_a_scheme_pays_each_side_its_own_reward(): void
    {
        $s = $this->scheme();
        $a = $this->player();
        $f = $this->refer($a);
        $this->assertSame($s->id, Referral::query()->where('friend_id', $f->id)->value('referral_scheme_id'));
        $this->buy($f, 500);
        $ref = Referral::query()->where('friend_id', $f->id)->firstOrFail();
        $this->assertSame('rewarded', $ref->status);
        $this->assertSame(300, $this->wallet()->balances($a)['bonus']);
        $this->assertSame(5, Voucher::query()->where('user_id', $f->id)->where('referral_id', $ref->id)->value('tickets'));
        $mine = $this->actingAs($a)->getJson('/api/me/referrals')->assertOk()->json();
        $this->assertSame('£3.00 bonus credit', $mine['scheme']['referrerReward']);
        $this->assertSame('5 free entries', $mine['scheme']['friendReward']);
        $this->assertSame(1, $mine['rewards'][0]['level']);
    }

    public function test_under_the_minimum_or_not_first_misses_but_any_order_waits(): void
    {
        $this->scheme();
        $a = $this->player();
        $f = $this->refer($a);
        $this->buy($f, 200);
        $this->assertSame('missed', Referral::query()->where('friend_id', $f->id)->value('status'));

        ReferralScheme::query()->update(['qualifying' => 'any_order']);
        $g = $this->refer($this->player());
        $this->buy($g, 200);
        $this->assertSame('pending', Referral::query()->where('friend_id', $g->id)->value('status'));
        $this->buy($g, 600);
        $this->assertSame('rewarded', Referral::query()->where('friend_id', $g->id)->value('status'));
    }

    public function test_multi_level_rewards_go_up_the_chain(): void
    {
        $p = LoyaltyProgramme::query()->create(['name' => 'Club', 'active' => true, 'points_per_pound' => 0]);
        $this->scheme(['referrer_reward_type' => 'points', 'referrer_reward_amount' => 100, 'loyalty_programme_id' => $p->id,
            'levels' => [['type' => 'points', 'amount' => 50], ['type' => 'credit', 'amount' => 25]]]);
        $top = $this->player();
        $mid = $this->refer($top);
        $low = $this->refer($mid);
        $friend = $this->refer($low);
        $this->buy($friend, 500);
        $loyalty = app(Loyalty::class);
        $this->assertSame(100, $loyalty->balance($low->id, $p->id), 'level 1: the referrer');
        $this->assertSame(50, $loyalty->balance($mid->id, $p->id), 'level 2');
        $this->assertSame(25, $this->wallet()->balances($top)['bonus'], 'level 3');
        $this->assertSame([0, 1, 2, 3], ReferralReward::query()->whereHas('referral', fn ($q) => $q->where('friend_id', $friend->id))->orderBy('level')->pluck('level')->all());
    }

    public function test_a_refund_reverses_credit_points_and_unused_vouchers(): void
    {
        $p = LoyaltyProgramme::query()->create(['name' => 'Club', 'active' => true, 'points_per_pound' => 0]);
        $this->scheme(['levels' => [['type' => 'points', 'amount' => 40]], 'loyalty_programme_id' => $p->id]);
        $top = $this->player();
        $a = $this->refer($top);
        $f = $this->refer($a);
        $order = $this->buy($f, 500);
        $this->assertSame(300, $this->wallet()->balances($a)['bonus']);
        $order->forceFill(['status' => 'refunded'])->save();
        $this->assertSame(0, $this->wallet()->balances($a)['bonus']);
        $this->assertSame(0, app(Loyalty::class)->balance($top->id, $p->id));
        $this->assertSame('clawed_back', Voucher::query()->where('user_id', $f->id)->value('status'));
        $this->assertSame(0, ReferralReward::query()->where('status', 'given')->whereIn('type', ['credit', 'points'])->count());
    }

    public function test_members_only_schemes_dates_and_caps(): void
    {
        $this->scheme(['who_can_refer' => 'members']);
        $a = $this->player();
        $f = $this->refer($a);
        $this->assertNull(Referral::query()->where('friend_id', $f->id)->first(), 'not a member: no referral');
        $this->assertTrue($this->actingAs($a)->getJson('/api/me/referrals')->json('schemeClosed'));

        ReferralScheme::query()->delete();
        $this->scheme(['ends_at' => now()->subDay()]);
        $g = $this->refer($this->player());
        $this->assertNull(Referral::query()->where('friend_id', $g->id)->first(), 'ended');

        ReferralScheme::query()->delete();
        $this->scheme(['monthly_cap' => 1]);
        $b = $this->player();
        $this->buy($this->refer($b), 500);
        $second = $this->refer($b);
        $this->buy($second, 500);
        $this->assertSame('capped', Referral::query()->where('friend_id', $second->id)->value('status'));
        $this->assertSame(300, $this->wallet()->balances($b)['bonus'], 'one referrer reward this month');
        $this->assertSame(5, Voucher::query()->where('user_id', $second->id)->value('tickets'), 'the friend still gets theirs');
    }

    public function test_joining_a_membership_can_be_the_qualifying_action(): void
    {
        $this->scheme(['qualifying' => 'membership', 'min_spend_pence' => 0]);
        $tier = MembershipTier::query()->create(['name' => 'Silver', 'price_pence' => 999, 'active' => true]);
        $a = $this->player();
        $f = $this->refer($a);
        $this->buy($f, 500);
        $this->assertSame('pending', Referral::query()->where('friend_id', $f->id)->value('status'));
        $this->topUp($f, 999);
        app(Memberships::class)->join($f, $tier, 'wallet');
        $this->assertSame('rewarded', Referral::query()->where('friend_id', $f->id)->value('status'));
    }

    public function test_with_no_schemes_the_settings_rewards_still_apply(): void
    {
        $a = $this->player();
        $f = $this->refer($a);
        $this->assertNull(Referral::query()->where('friend_id', $f->id)->value('referral_scheme_id'));
        $this->assertNull($this->actingAs($a)->getJson('/api/me/referrals')->json('scheme'));
    }

    public function test_the_admin_creates_a_scheme(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $this->actingAs($admin->refresh());
        Livewire::test(CreateReferralScheme::class)->fillForm([
            'name' => 'Members Bring Members', 'active' => true, 'who_can_refer' => 'members', 'qualifying' => 'membership', 'min_spend_pence' => '0',
            'friend_reward_type' => 'credit', 'friend_reward_amount' => 500, 'referrer_reward_type' => 'tickets', 'referrer_reward_amount' => 10,
            'levels' => [['type' => 'tickets', 'amount' => 2]],
        ])->call('create')->assertHasNoFormErrors();
        $s = ReferralScheme::query()->where('name', 'Members Bring Members')->firstOrFail();
        $this->assertSame([['type' => 'tickets', 'amount' => 2]], array_values($s->levels));
        $this->get('/admin/referral-schemes')->assertOk()->assertSee('Members Bring Members');
    }
}

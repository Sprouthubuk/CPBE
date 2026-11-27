<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Amendment 4: subscriptions can give a discount on tickets and access to members-only competitions. */
class SubscriptionPerksTest extends TestCase
{
    private function member(MembershipTier $t): User
    {
        $u = $this->player();
        Membership::query()->create(['user_id' => $u->id, 'membership_tier_id' => $t->id, 'status' => 'active', 'pay_with' => 'wallet',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);

        return $u;
    }

    private function buy(User $u, $c, int $qty)
    {
        $this->topUp($u, 10000);

        return $this->actingAs($u)->postJson('/api/checkout', ['lines' => [['competitionId' => $c->id, 'quantity' => $qty]], 'payWith' => 'wallet', 'useWallet' => true, 'requestId' => (string) Str::uuid()]);
    }

    public function test_members_pay_their_tiers_discounted_price(): void
    {
        $gold = MembershipTier::query()->create(['name' => 'Gold', 'price_pence' => 1999, 'active' => true, 'ticket_discount_pct' => 10]);
        $c = $this->competition('draw', ['price_pence' => 99, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $u = $this->member($gold);
        $this->actingAs($u)->getJson('/api/competitions/'.$c->slug)->assertJsonPath('memberPricePence', 89);
        $this->buy($u, $c, 10)->assertSuccessful();
        $line = OrderLine::query()->latest('id')->firstOrFail();
        $this->assertSame([89, 890], [(int) $line->unit_pence, (int) $line->line_pence]);
        $this->assertContains('10% off tickets', $gold->perkList());

        $this->buy($this->player(), $c, 10)->assertSuccessful();
        $this->assertSame(990, (int) OrderLine::query()->latest('id')->value('line_pence'));
    }

    public function test_members_only_competitions_sell_to_members_but_the_free_postal_route_stays_open(): void
    {
        $gold = MembershipTier::query()->create(['name' => 'Gold', 'price_pence' => 1999, 'active' => true]);
        $c = $this->competition('draw', ['members_only_tier_ids' => [$gold->id]]);
        $this->getJson('/api/competitions/'.$c->slug)->assertJsonPath('membersOnly', ['Gold']);
        $outsider = $this->player();
        $this->buy($outsider, $c, 2)->assertStatus(422);
        $this->buy($this->member($gold), $c, 2)->assertSuccessful();
        $this->assertSame('entered', app(\App\Services\Postal::class)->log($c, $outsider->email, now(), $this->admin())->status);
        $this->assertContains('Members-only competitions', $gold->perkList());
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\BundleTier;
use App\Models\Competition;
use App\Models\OrderLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Amendment 5: automatic pricing and offers, applied the same on the page, in the basket and at checkout. */
class PriceRulesTest extends TestCase
{
    private function draw(array $attrs = []): Competition
    {
        return $this->competition('draw', $attrs + ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000, 'closes_at' => now()->addDays(5)]);
    }

    private function buy($u, Competition $c, int $qty, array $extra = [])
    {
        return $this->actingAs($u)->postJson('/api/checkout', ['lines' => [['competitionId' => $c->id, 'quantity' => $qty] + $extra],
            'payWith' => 'wallet', 'useWallet' => true, 'requestId' => (string) Str::uuid()]);
    }

    private function ticketsHeld($u, Competition $c): int
    {
        return app(\App\Services\CompetitionState::class)->held($c->refresh(), $u->ownerRef());
    }

    public function test_an_early_bird_price_applies_until_its_time_then_the_published_price_returns(): void
    {
        $c = $this->draw();
        $c->priceRules()->create(['name' => 'Early Bird', 'trigger' => 'hours_left_more', 'trigger_value' => 72, 'price_pence' => 75]);
        $u = $this->player();
        $this->topUp($u, 5000);

        $this->getJson('/api/competitions/'.$c->slug)->assertJsonPath('pricePence', 75)->assertJsonPath('basePricePence', 100)
            ->assertJsonPath('offer.label', 'Early Bird')->assertJsonPath('offer.until', $c->closes_at->copy()->subHours(72)->toIso8601ZuluString());
        $this->postJson('/api/basket/price', ['lines' => [['competitionId' => $c->id, 'quantity' => 4]]])->assertJsonPath('totalPence', 300)
            ->assertJsonPath('lines.0.offerLabel', 'Early Bird');
        $this->buy($u, $c, 4)->assertSuccessful();
        $line = OrderLine::query()->latest('id')->firstOrFail();
        $this->assertSame([75, 300], [(int) $line->unit_pence, (int) $line->line_pence]);
        $this->assertNotNull($line->price_rule_id);

        Carbon::setTestNow(now()->addDays(3));
        $this->getJson('/api/competitions/'.$c->slug)->assertJsonPath('pricePence', 100)->assertJsonPath('offer', null);
        $this->buy($u, $c, 4)->assertSuccessful();
        $this->assertSame(400, (int) OrderLine::query()->latest('id')->value('line_pence'));
    }

    public function test_bonus_tickets_are_free_and_entered_like_paid_ones(): void
    {
        $c = $this->draw();
        $c->priceRules()->create(['name' => 'Buy 5 Get 1 Free', 'trigger' => 'always', 'bonus_buy' => 5, 'bonus_get' => 1]);
        $u = $this->player();
        $this->topUp($u, 5000);
        $this->buy($u, $c, 10)->assertSuccessful();
        $line = OrderLine::query()->latest('id')->firstOrFail();
        $this->assertSame([12, 10, 2, 1000], [(int) $line->quantity, (int) $line->paid_quantity, (int) $line->free_quantity, (int) $line->line_pence]);
        $this->assertSame(12, $this->ticketsHeld($u, $c));
        $this->getJson('/api/competitions/'.$c->slug)->assertJsonPath('offer.bonus', ['buy' => 5, 'get' => 1]);
    }

    public function test_rules_follow_tickets_sold_and_the_first_matching_rule_wins(): void
    {
        $c = $this->draw(['max_tickets' => 100, 'max_per_person' => 100]);
        $c->priceRules()->create(['name' => 'Launch Price', 'sort' => 1, 'trigger' => 'sold_pct_below', 'trigger_value' => 10, 'price_pence' => 50]);
        $c->priceRules()->create(['name' => 'Last Chance', 'sort' => 2, 'trigger' => 'sold_pct_at_least', 'trigger_value' => 10, 'price_pence' => 120]);
        $c->priceRules()->create(['name' => 'Always Cheaper', 'sort' => 3, 'trigger' => 'always', 'price_pence' => 10]);
        $u = $this->player();
        $this->topUp($u, 10000);
        $this->getJson('/api/competitions/'.$c->slug)->assertJsonPath('pricePence', 50);
        $this->buy($u, $c, 10)->assertSuccessful();
        $this->getJson('/api/competitions/'.$c->slug)->assertJsonPath('pricePence', 120)->assertJsonPath('offer.label', 'Last Chance');
        $c->priceRules()->where('name', 'Last Chance')->update(['is_active' => false]);
        $this->getJson('/api/competitions/'.$c->slug)->assertJsonPath('pricePence', 10);
    }

    public function test_demand_rule_follows_tickets_sold_in_the_last_day(): void
    {
        $c = $this->draw();
        $c->priceRules()->create(['name' => 'Hot Right Now', 'trigger' => 'sold_24h_at_least', 'trigger_value' => 20, 'bonus_buy' => 10, 'bonus_get' => 2]);
        $u = $this->player();
        $this->topUp($u, 10000);
        $this->getJson('/api/competitions/'.$c->slug)->assertJsonPath('offer', null);
        $this->buy($u, $c, 20)->assertSuccessful();
        $this->getJson('/api/competitions/'.$c->slug)->assertJsonPath('offer.label', 'Hot Right Now');
        Carbon::setTestNow(now()->addHours(25));
        $this->getJson('/api/competitions/'.$c->slug)->assertJsonPath('offer', null);
    }

    public function test_a_rule_can_limit_the_bundles_on_offer(): void
    {
        $small = BundleTier::query()->create(['name' => 'Small', 'paid_qty' => 5, 'free_qty' => 1, 'is_active' => true, 'sort_order' => 1]);
        $big = BundleTier::query()->create(['name' => 'Big', 'paid_qty' => 20, 'free_qty' => 5, 'is_active' => true, 'sort_order' => 2]);
        $c = $this->draw(['bundles_enabled' => true, 'max_bundle_share_pct' => 10]);
        $c->bundleTiers()->sync([$small->id => ['is_featured' => false], $big->id => ['is_featured' => true]]);
        $this->assertCount(2, $this->getJson('/api/competitions/'.$c->slug)->json('bundles'));
        $c->priceRules()->create(['name' => 'Final Hours', 'trigger' => 'always', 'bundle_tier_ids' => [$small->id], 'price_pence' => 80]);
        $bundles = $this->getJson('/api/competitions/'.$c->slug)->json('bundles');
        $this->assertSame([[$small->id, 400]], array_map(fn ($b) => [$b['id'], $b['pricePence']], $bundles));
        $u = $this->player();
        $this->topUp($u, 10000);
        $this->buy($u, $c, 1, ['bundleId' => $big->id])->assertStatus(422);
        $this->buy($u, $c, 1, ['bundleId' => $small->id])->assertSuccessful();
        $this->assertSame(6, $this->ticketsHeld($u, $c));
    }

    public function test_a_live_draw_can_release_tickets_in_stages_but_the_maximum_never_changes(): void
    {
        $c = $this->draw(['max_tickets' => 100, 'max_per_person' => 100]);
        $c->priceRules()->create(['name' => 'First Release', 'sort' => 1, 'trigger' => 'hours_left_more', 'trigger_value' => 48, 'release_pct' => 20]);
        $u = $this->player();
        $this->topUp($u, 20000);
        $this->buy($u, $c, 21)->assertStatus(422);
        $this->buy($u, $c, 20)->assertSuccessful();
        $this->postJson('/api/basket/price', ['lines' => [['competitionId' => $c->id, 'quantity' => 1]]])
            ->assertJsonPath('lines.0.problem', 'All the tickets on sale for now are taken. More go on sale soon.');
        $this->getJson('/api/competitions/'.$c->slug)->assertJsonPath('maxTickets', 100)->assertJsonPath('ticketsLeft', 80);
        // A free postal entry is never held back.
        $admin = $this->admin();
        $this->assertSame('entered', app(\App\Services\Postal::class)->log($c, $this->player()->email, now(), $admin)->status);
        Carbon::setTestNow(now()->addDays(4));
        $this->buy($u, $c, 50)->assertSuccessful();
    }

    public function test_changes_to_rules_on_sale_are_logged_and_bad_rules_are_refused(): void
    {
        $c = $this->draw();
        $this->actingAs($this->admin());
        $r = $c->priceRules()->create(['name' => 'Flash Sale', 'trigger' => 'always', 'price_pence' => 60]);
        $r->update(['price_pence' => 55]);
        $r->delete();
        $this->assertSame(['competition.price_rule_added', 'competition.price_rule_changed', 'competition.price_rule_removed'],
            AdminAction::query()->where('subject', $c->id)->where('action', 'like', 'competition.price_rule%')->orderBy('id')->pluck('action')->all());

        foreach ([['trigger' => 'sold_pct_below', 'price_pence' => 50], ['trigger' => 'always'], ['trigger' => 'always', 'bonus_buy' => 5], ['trigger' => 'nope', 'price_pence' => 5]] as $bad) {
            try {
                $c->priceRules()->create(['name' => 'Bad'] + $bad);
                $this->fail('Accepted '.json_encode($bad));
            } catch (\DomainException) {
            }
        }
    }

    public function test_an_admin_adds_a_rule_on_the_competition_form(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('competitions/x.webp', 'img');
        $c = $this->draw(['image' => 'competitions/x.webp']);
        $a = $this->admin();
        $a->saveAppAuthenticationSecret(app(\Filament\Auth\MultiFactor\App\AppAuthentication::class)->generateSecret());
        $this->actingAs($a->refresh());
        \Filament\Facades\Filament::setCurrentPanel('admin');
        $form = \Livewire\Livewire::test(\App\Filament\Resources\Competitions\Pages\EditCompetition::class, ['record' => $c->id]);
        $form->set('data.priceRules', ['new' => ['name' => 'Nothing To Change', 'is_active' => true, 'trigger' => 'always']])->call('save')
            ->assertHasFormErrors();
        $form->set('data.priceRules', ['new' => ['name' => 'Weekend Deal', 'is_active' => true, 'trigger' => 'always', 'price_pence' => '0.80', 'bonus_buy' => 10, 'bonus_get' => 2]])
            ->call('save')->assertHasNoFormErrors();
        $r = $c->priceRules()->firstOrFail();
        $this->assertSame(['Weekend Deal', 80, 10, 2, null], [$r->name, (int) $r->price_pence, (int) $r->bonus_buy, (int) $r->bonus_get, $r->bundle_tier_ids]);
        $this->getJson('/api/competitions/'.$c->slug)->assertJsonPath('pricePence', 80);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Orders;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Amendment 11: the government's Voluntary Code of Good Practice for Prize Draw Operators. */
class CodeComplianceTest extends TestCase
{
    private function cardCheckout($u, $c, int $tickets)
    {
        return $this->actingAs($u)->postJson('/api/checkout', ['lines' => [['competitionId' => $c->id, 'quantity' => $tickets]], 'payWith' => 'card',
            'requestId' => (string) Str::uuid()]);
    }

    private function lastOrder(): Order
    {
        return Order::query()->latest('id')->firstOrFail();
    }

    private function debitOnly(Order $o): bool
    {
        return (bool) Cache::get('fakepay:'.$o->gateway_ref)['debitOnly'];
    }

    private function pay(Order $o, string $card): Order
    {
        $this->gateway()->settle($o->gateway_ref, 'paid', $card);

        return app(Orders::class)->syncWithGateway($o);
    }

    public function test_instant_wins_are_debit_card_only(): void
    {
        $c = $this->competition('instant', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $u = $this->player();
        $this->postJson('/api/basket/price', ['lines' => [['competitionId' => $c->id, 'quantity' => 5]]])
            ->assertJsonPath('cardNote', 'Instant wins can’t be paid by credit card: please use a debit card.');
        $this->cardCheckout($u, $c, 5)->assertCreated();
        $o = $this->lastOrder();
        $this->assertFalse($o->credit_allowed);
        $this->assertTrue($this->debitOnly($o));
    }

    public function test_draws_take_credit_cards_up_to_250_a_month_then_debit_only(): void
    {
        $c = $this->competition('draw', ['price_pence' => 1000, 'max_tickets' => 10000, 'max_per_person' => 10000]);
        $u = $this->player();
        $this->cardCheckout($u, $c, 20)->assertCreated(); // £200
        $o = $this->lastOrder();
        $this->assertTrue($o->credit_allowed);
        $this->assertFalse($this->debitOnly($o));
        $this->assertSame('paid', $this->pay($o, 'credit')->status);

        $this->actingAs($u)->postJson('/api/basket/price', ['lines' => [['competitionId' => $c->id, 'quantity' => 6]]])
            ->assertJsonPath('cardNote', 'You’ve reached this month’s credit card limit for draws: please use a debit card.');
        $this->cardCheckout($u, $c, 6)->assertCreated(); // £60 would make £260
        $this->assertTrue($this->debitOnly($this->lastOrder()));
        $this->cardCheckout($u, $c, 5)->assertCreated(); // £50 makes exactly £250
        $this->assertFalse($this->debitOnly($this->lastOrder()));
    }

    public function test_top_ups_and_memberships_are_debit_only(): void
    {
        $u = $this->player();
        $this->actingAs($u)->postJson('/api/me/deposits', ['amountPence' => 1000, 'requestId' => (string) Str::uuid()])->assertSuccessful();
        $this->assertTrue($this->debitOnly($this->lastOrder()));
    }

    public function test_a_credit_card_where_none_was_allowed_issues_nothing_and_is_flagged(): void
    {
        $c = $this->competition('instant', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $u = $this->player();
        $this->cardCheckout($u, $c, 5)->assertCreated();
        $o = $this->pay($this->lastOrder(), 'credit');
        $this->assertSame('paid', $o->status);
        $this->assertTrue($o->needs_attention);
        $this->assertStringContainsString('credit card', $o->attention_reason);
        $this->assertSame(0, app(\App\Services\CompetitionState::class)->held($c->refresh(), $u->ownerRef()));

        $this->actingAs($u)->postJson('/api/me/deposits', ['amountPence' => 1000, 'requestId' => (string) Str::uuid()])->assertSuccessful();
        $this->pay($this->lastOrder(), 'credit');
        $this->assertSame(0, $this->wallet()->balances($u)['deposit']);
    }

    public function test_a_spend_limit_is_offered_before_a_second_paid_entry_and_never_holds_up_a_postal_entry(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $u = $this->player(['limits_prompted_at' => null]);
        $this->topUp($u, 5000);
        $buy = fn () => $this->actingAs($u)->postJson('/api/checkout', ['lines' => [['competitionId' => $c->id, 'quantity' => 2]], 'payWith' => 'wallet', 'useWallet' => true, 'requestId' => (string) Str::uuid()]);
        $this->actingAs($u)->getJson('/api/me')->assertJsonPath('limitsPrompt', false);
        $buy()->assertSuccessful();
        $this->getJson('/api/me')->assertJsonPath('limitsPrompt', true);
        $buy()->assertStatus(409)->assertJsonPath('code', 'limits_prompt');
        $this->postJson('/api/me/deposits', ['amountPence' => 1000])->assertStatus(409)->assertJsonPath('code', 'limits_prompt');
        $this->assertSame('entered', app(\App\Services\Postal::class)->log($c, $u->email, now(), $this->admin())->status);

        $this->actingAs($u)->postJson('/api/me/limits/skip')->assertOk();
        $this->getJson('/api/me')->assertJsonPath('limitsPrompt', false);
        $buy()->assertSuccessful();

        $v = $this->player(['limits_prompted_at' => null]);
        $this->topUp($v, 5000);
        $this->actingAs($v)->postJson('/api/checkout', ['lines' => [['competitionId' => $c->id, 'quantity' => 2]], 'payWith' => 'wallet', 'useWallet' => true, 'requestId' => (string) Str::uuid()])->assertSuccessful();
        $this->postJson('/api/me/limits', ['monthlyDepositPence' => 0])->assertOk();
        $this->getJson('/api/me')->assertJsonPath('limitsPrompt', false);
    }

    public function test_a_monthly_limit_can_be_set_at_sign_up(): void
    {
        $this->postJson('/api/auth/register', ['firstName' => 'Ana', 'lastName' => 'Lima', 'email' => 'ana@example.com', 'password' => 'a-long-password',
            'dateOfBirth' => '1990-01-01', 'monthlyLimitPence' => 3000])->assertSuccessful();
        $u = \App\Models\User::query()->where('email', 'ana@example.com')->firstOrFail();
        $this->assertSame(3000, (int) $u->limits->monthly_deposit_pence);
        $this->assertNotNull($u->limits_prompted_at);
        $this->postJson('/api/auth/register', ['firstName' => 'B', 'lastName' => 'C', 'email' => 'b@example.com', 'password' => 'a-long-password',
            'dateOfBirth' => '1990-01-01', 'monthlyLimitPence' => 200])->assertStatus(422);
    }

    public function test_instant_wins_are_never_the_majority_of_competitions_running(): void
    {
        \App\Models\Setting::put('code_instant_majority_guard', '1');
        try {
            $this->competition('instant');
            $this->fail('Published an instant win with nothing else running');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Voluntary Code 1.10', $e->getMessage());
        }
        $this->competition('draw');
        $this->competition('instant'); // 1 of 2: half, not a majority
        $this->expectException(\DomainException::class);
        $this->competition('instant'); // would be 2 of 3
    }

    public function test_signs_of_harm_are_flagged_for_a_person_who_records_the_step_taken(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 10000, 'max_per_person' => 10000]);
        $u = $this->player();
        $this->actingAs($u)->postJson('/api/me/limits', ['dailyDepositPence' => 500])->assertOk();
        $this->cardCheckout($u, $c, 10)->assertStatus(403);
        $this->assertSame(['limit_reached'], \App\Models\HarmFlag::query()->pluck('indicator')->all());
        $this->cardCheckout($u, $c, 10)->assertStatus(403); // once a day
        $this->assertSame(1, \App\Models\HarmFlag::query()->count());
        $this->postJson('/api/me/limits', ['dailyDepositPence' => 5000])->assertOk(); // a raise waits, and is flagged
        $this->assertSame(1, \App\Models\HarmFlag::query()->where('indicator', 'limit_raised')->count());

        \App\Models\Setting::put('harm_spend_30d_pence', '1000');
        $big = $this->player();
        $this->cardCheckout($big, $c, 15)->assertCreated();
        $this->pay($this->lastOrder(), 'debit');
        $this->assertSame(1, app(\App\Services\HarmSignals::class)->scan());
        $this->assertSame(0, app(\App\Services\HarmSignals::class)->scan());

        $flag = \App\Models\HarmFlag::query()->where('indicator', 'spend_30d')->firstOrFail();
        $a = \App\Models\User::factory()->admin()->create();
        $a->saveAppAuthenticationSecret(app(\Filament\Auth\MultiFactor\App\AppAuthentication::class)->generateSecret());
        $this->actingAs($a->refresh());
        \Filament\Facades\Filament::setCurrentPanel('admin');
        \Livewire\Livewire::test(\App\Filament\Pages\PlayerProtection::class)->callTableAction('review', $flag, ['step' => 'hold', 'note' => 'Spoke to them, agreed a pause'])
            ->assertHasNoTableActionErrors();
        $this->assertNotNull($big->refresh()->on_hold_at);
        $this->assertStringStartsWith('Put on hold', $flag->refresh()->outcome);
        $this->assertSame('on_hold', app(\App\Services\PlayCheck::class)->block($big));
    }
}

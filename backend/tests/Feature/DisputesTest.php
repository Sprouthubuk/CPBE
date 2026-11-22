<?php

namespace Tests\Feature;

use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Dispute;
use App\Models\LoyaltyProgramme;
use App\Models\Order;
use App\Models\User;
use App\Services\Loyalty;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** SOW 2.15: log chargebacks and disputes against orders, with a printable evidence pack. */
class DisputesTest extends TestCase
{
    private function adminIn(string $role = 'admin'): User
    {
        $a = User::factory()->admin()->create();
        $a->forceFill(['admin_role' => $role])->save();
        $a->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $this->actingAs($a->refresh());

        return $a;
    }

    /** A card order placed through the API (so its IP is recorded) and paid on the fake payment page. */
    private function cardOrder(): Order
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000,
            'skill_question' => 'What is 2 + 3?', 'skill_options' => ['4', '5'], 'skill_answer' => 1]);
        $u = $this->player();
        $r = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->actingAs($u)->postJson('/api/checkout', [
            'lines' => [['competitionId' => $c->id, 'quantity' => 5, 'skillAnswer' => 1]], 'payWith' => 'card', 'requestId' => (string) Str::uuid()])->assertCreated();
        $o = Order::query()->where('number', $r->json('order.number'))->firstOrFail();
        $this->gateway()->settle($o->gateway_ref, 'paid');
        $this->actingAs($u)->postJson("/api/orders/{$o->number}/return", [])->assertOk();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $o->refresh();
    }

    public function test_an_order_records_the_ip_it_came_from(): void
    {
        $this->assertSame('203.0.113.9', $this->cardOrder()->ip);
    }

    public function test_a_dispute_is_logged_and_its_evidence_pack_has_everything(): void
    {
        $o = $this->cardOrder();
        $admin = $this->adminIn('finance');
        Livewire::test(ListOrders::class)->callTableAction('dispute', $o, data: [
            'kind' => 'chargeback', 'reference' => 'CB-123', 'amount_pence' => '5.00', 'reason' => 'Fraudulent: card not present', 'respond_by' => now()->addDays(7)->toDateString(),
        ])->assertHasNoTableActionErrors();
        $d = Dispute::query()->firstOrFail();
        $this->assertSame(500, $d->amount_pence);
        $this->get('/admin/disputes')->assertOk()->assertSee('CB-123');
        $page = $this->get('/admin/evidence/'.$o->number)->assertOk();
        foreach ([$o->user->email, '203.0.113.9', 'What is 2 + 3? → 5', 'Signed up', 'Weekly deposit limit', 'Fraudulent: card not present'] as $s) {
            $page->assertSee($s, false);
        }
    }

    public function test_only_finance_and_admins_see_evidence(): void
    {
        $o = $this->cardOrder();
        $this->adminIn('support');
        $this->get('/admin/evidence/'.$o->number)->assertForbidden();
        $this->get('/admin/disputes')->assertForbidden();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->get('/admin/evidence/'.$o->number)->assertForbidden();
    }

    public function test_a_lost_chargeback_refunds_the_order_and_reverses_its_points(): void
    {
        $p = LoyaltyProgramme::query()->create(['name' => 'Club', 'active' => true, 'points_per_pound' => 10]);
        $o = $this->cardOrder();
        $this->assertSame(50, app(Loyalty::class)->balance($o->user_id, $p->id));
        $this->adminIn();
        Livewire::test(ListOrders::class)->callTableAction('dispute', $o, data: ['kind' => 'chargeback', 'amount_pence' => '5.00', 'reason' => 'Not recognised'])
            ->assertHasNoTableActionErrors();
        $d = Dispute::query()->firstOrFail();
        Livewire::test(ListDisputes::class)->callTableAction('status', $d, data: ['status' => 'lost', 'notes' => 'Bank sided with cardholder'])->assertHasNoTableActionErrors();
        $this->assertSame('refunded', $o->refresh()->status);
        $this->assertNotNull($d->refresh()->closed_at);
        $this->assertSame(0, app(Loyalty::class)->balance($o->user_id, $p->id));
    }
}

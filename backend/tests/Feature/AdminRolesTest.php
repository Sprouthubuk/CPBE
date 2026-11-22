<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AdminAction;
use App\Models\User;
use App\Services\PlayCheck;
use App\Support\Roles;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Livewire\Livewire;
use Tests\TestCase;

/** SOW 2.14 / 2.16: separate admin and staff roles, each seeing only its own areas. */
class AdminRolesTest extends TestCase
{
    private function member(string $role): User
    {
        $u = User::factory()->admin()->create();
        $u->forceFill(['admin_role' => $role])->save();
        $u->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());

        return $u->refresh();
    }

    public function test_each_role_sees_only_its_areas(): void
    {
        $support = $this->member('support');
        $this->actingAs($support)->get('/admin')->assertOk();
        foreach (['orders', 'users', 'id-checks', 'prize-claims', 'postal-entries', 'contact-messages'] as $p) {
            $this->get('/admin/'.$p)->assertOk();
        }
        foreach (['site-settings', 'competitions', 'promo-codes', 'withdrawals'] as $p) {
            $this->get('/admin/'.$p)->assertForbidden();
        }

        $finance = $this->member('finance');
        $this->actingAs($finance)->get('/admin/withdrawals')->assertOk();
        $this->get('/admin/orders')->assertOk();
        $this->get('/admin/competitions')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();

        $marketing = $this->member('marketing');
        $this->actingAs($marketing)->get('/admin/promo-codes')->assertOk();
        $this->get('/admin/orders')->assertForbidden();

        $manager = $this->member('manager');
        $this->actingAs($manager)->get('/admin/competitions')->assertOk();
        $this->get('/admin/site-settings')->assertForbidden();
    }

    public function test_an_admin_without_a_stored_role_is_a_full_admin(): void
    {
        $a = User::factory()->admin()->create();
        $this->assertSame('admin', Roles::of($a));
        $this->assertTrue(Roles::can($a, 'settings'));
        $this->assertNull(Roles::of($this->player()));
        $this->assertFalse(Roles::can($this->player(), 'orders'));
    }

    public function test_only_an_admin_can_give_someone_a_role_and_it_is_logged(): void
    {
        $admin = $this->member('admin');
        $p = $this->player();
        $this->actingAs($admin);
        Livewire::test(ListUsers::class)->callTableAction('role', $p, data: ['role' => 'support', 'reason' => 'New support hire'])->assertHasNoTableActionErrors();
        $p->refresh();
        $this->assertTrue($p->is_admin);
        $this->assertSame('support', $p->admin_role);
        $this->assertSame('staff', app(PlayCheck::class)->block($p));
        $this->assertTrue(AdminAction::query()->where('action', 'player.role')->where('subject', (string) $p->id)->exists());

        // Taking the role away leaves them unable to reach the admin.
        Livewire::test(ListUsers::class)->callTableAction('role', $p, data: ['role' => 'none', 'reason' => 'Left'])->assertHasNoTableActionErrors();
        $this->assertFalse($p->refresh()->is_admin);
        $this->assertNull($p->admin_role);

        $manager = $this->member('manager');
        $this->actingAs($manager);
        Livewire::test(ListUsers::class)->assertTableActionHidden('role', $p);
    }

    public function test_the_make_admin_command_takes_a_role(): void
    {
        User::factory()->create(['email' => 'cs@example.com']);
        $this->artisan('platform:make-admin', ['email' => 'cs@example.com', '--role' => 'support'])->assertSuccessful();
        $this->assertSame('support', User::query()->where('email', 'cs@example.com')->value('admin_role'));
        $this->artisan('platform:make-admin', ['email' => 'cs@example.com', '--role' => 'boss'])->assertFailed();
    }
}

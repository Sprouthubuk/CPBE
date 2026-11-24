<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Checks added by the security and compliance audit (docs/audit.md). */
class AuditTest extends TestCase
{
    public function test_a_new_password_signs_out_every_other_session(): void
    {
        $u = $this->player(['email' => 'a@example.com']);
        $u->forceFill(['password' => 'password123'])->save();
        $h = ['Referer' => 'https://platform.test/', 'Origin' => 'https://platform.test'];
        $r = $this->withHeaders($h)->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'password123']);
        $r->assertOk();
        $cookies = collect($r->headers->getCookies())->mapWithKeys(fn ($c) => [$c->getName() => $c->getValue()])->all();
        $this->withHeaders($h)->withCredentials()->withUnencryptedCookies($cookies)->getJson('/api/me')->assertOk();
        $u->refresh()->forceFill(['password' => 'newpassword99'])->save(); // a reset elsewhere
        $this->app['auth']->forgetGuards();
        $this->withHeaders($h)->withCredentials()->withUnencryptedCookies($cookies)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_only_admins_who_check_id_can_open_a_players_id_documents(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $u = $this->player();
        \Illuminate\Support\Facades\Storage::disk('local')->put('id-checks/'.$u->id.'/p.jpg', 'x');
        $check = \App\Models\IdCheck::query()->create(['user_id' => $u->id, 'photo_path' => 'id-checks/'.$u->id.'/p.jpg', 'address_path' => 'id-checks/'.$u->id.'/p.jpg']);
        foreach (['marketing' => 403, 'finance' => 403, 'support' => 200, 'manager' => 200, 'admin' => 200] as $role => $status) {
            $a = \App\Models\User::factory()->admin()->create();
            $a->forceFill(['admin_role' => $role])->save();
            $a->saveAppAuthenticationSecret(app(\Filament\Auth\MultiFactor\App\AppAuthentication::class)->generateSecret());
            $this->actingAs($a->refresh())->get(route('admin.id-check-file', [$check, 'photo']))->assertStatus($status);
        }
    }
}

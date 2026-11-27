<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\WebhookEndpoint;
use App\Services\DataExport;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Amendment 27: a full monthly copy of the database, to storage the Client controls. */
class DataExportTest extends TestCase
{
    private function open(string $path, ?string $password = null): \ZipArchive
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zt');
        file_put_contents($tmp, Storage::disk('client_export')->get($path));
        $z = new \ZipArchive;
        $this->assertTrue($z->open($tmp));
        if ($password) {
            $z->setPassword($password);
        }

        return $z;
    }

    public function test_every_table_is_exported_without_sign_in_secrets(): void
    {
        Storage::fake('client_export');
        $c = $this->competition('draw');
        $u = $this->player();
        ApiToken::issue('Xero', $this->admin());
        WebhookEndpoint::query()->create(['name' => 'CRM', 'url' => 'https://crm.example.com/h', 'events' => ['order.paid']]);

        $this->artisan('platform:export-data')->assertSuccessful();
        $files = Storage::disk('client_export')->files('exports');
        $this->assertCount(1, $files);
        $z = $this->open($files[0]);

        $manifest = json_decode($z->getFromName('manifest.json'), true);
        $this->assertArrayHasKey('verity_competitions', $manifest['tables'], "Verity's records go too");
        $this->assertArrayNotHasKey('sessions', $manifest['tables']);
        $users = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", $z->getFromName('users.jsonl'))));
        $this->assertContains($u->email, array_column($users, 'email'));
        $this->assertArrayNotHasKey('password', $users[0]);
        $this->assertArrayNotHasKey('app_authentication_secret', $users[0]);
        $this->assertStringNotContainsString('token_hash', $z->getFromName('api_tokens.jsonl'));
        $this->assertStringNotContainsString('"secret"', $z->getFromName('webhook_endpoints.jsonl'));
        $this->assertSame(1, $manifest['tables']['competitions']['rows']);
        $this->assertStringContainsString($c->slug, $z->getFromName('competitions.jsonl'));
        $this->assertStringContainsString('exports/', (string) \App\Models\Setting::get('data_export_last'));
    }

    public function test_a_password_encrypts_the_export_and_a_storage_failure_is_reported(): void
    {
        Storage::fake('client_export');
        config(['services.data_export.password' => 'client-chosen-password']);
        $this->competition('draw');
        [$path] = app(DataExport::class)->run('client_export');
        $z = $this->open($path);
        $this->assertFalse(@$z->getFromName('manifest.json'), 'unreadable without the password');
        $this->assertNotFalse($this->open($path, 'client-chosen-password')->getFromName('manifest.json'));

        config(['services.data_export.disk' => 'missing_disk']);
        $this->artisan('platform:export-data')->assertFailed();
    }

    public function test_it_runs_on_the_first_of_each_month(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'platform:export-data'));
        $this->assertCount(1, $events);
        $this->assertSame('17 3 1 * *', $events->first()->expression);
    }
}

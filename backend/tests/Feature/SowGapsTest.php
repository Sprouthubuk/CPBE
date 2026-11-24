<?php

namespace Tests\Feature;

use App\Filament\Pages\LiveDraw;
use App\Filament\Pages\WinnerPhotos;
use App\Filament\Resources\Competitions\Pages\EditCompetition;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AdminAction;
use App\Models\Competition;
use App\Models\PostalEntry;
use App\Models\Setting;
use App\Models\User;
use App\Models\WinnerMedia;
use App\Services\Postal;
use App\Support\PrizeTable;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Sprouthub\Verity\Facades\Verity;
use Sprouthub\Verity\Models\Winner;
use Tests\TestCase;

/** SOW items added on top of the base build: wallet corrections, limits, the announcement bar, CSV imports, winner photos and the live draw. */
class SowGapsTest extends TestCase
{
    private function signIn(string $role = 'admin'): User
    {
        $a = User::factory()->admin()->create();
        $a->forceFill(['admin_role' => $role])->save();
        $a->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $this->actingAs($a->refresh());
        Filament::setCurrentPanel('admin');

        return $a;
    }

    private function buy(User $u, Competition $c, int $qty)
    {
        return $this->actingAs($u)->postJson('/api/checkout', [
            'lines' => [['competitionId' => $c->id, 'quantity' => $qty]], 'payWith' => 'wallet', 'useWallet' => true, 'requestId' => (string) Str::uuid(),
        ]);
    }

    public function test_an_admin_removes_wallet_credit_with_a_reason_never_more_than_the_balance(): void
    {
        $this->signIn();
        $u = $this->player();
        $this->topUp($u, 1000, 'bonus');

        Livewire::test(ListUsers::class)->callTableAction('debit', $u, ['bucket' => 'bonus', 'amount' => '3.00', 'reason' => 'Added twice', 'confirm' => 'REMOVE 2.00']);
        $this->assertSame(1000, $this->wallet()->balances($u)['bonus']);

        Livewire::test(ListUsers::class)->callTableAction('debit', $u, ['bucket' => 'bonus', 'amount' => '11.00', 'reason' => 'Added twice', 'confirm' => 'REMOVE 11.00'])
            ->assertNotified('Not removed');
        $this->assertSame(1000, $this->wallet()->balances($u)['bonus']);

        Livewire::test(ListUsers::class)->callTableAction('debit', $u, ['bucket' => 'bonus', 'amount' => '3.00', 'reason' => 'Added twice', 'confirm' => 'remove 3.00']);
        $this->assertSame(700, $this->wallet()->balances($u)['bonus']);
        $a = AdminAction::query()->where('action', 'wallet.debit')->firstOrFail();
        $this->assertSame('Added twice', $a->reason);
        $this->assertSame(300, $a->detail['pence']);

        $this->expectException(\App\Exceptions\RefusedException::class);
        $this->wallet()->adminDebit($u->id, 'deposit', 1, 'Nothing there', 'admin-debit:x');
    }

    public function test_the_limit_per_player_is_optional_and_never_above_the_tickets(): void
    {
        $this->signIn();
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('competitions/x.webp', 'img');
        $c = $this->competition('draw', ['max_tickets' => 500, 'max_per_person' => 500, 'image' => 'competitions/x.webp'], publish: false);
        Livewire::test(EditCompetition::class, ['record' => $c->id])->assertFormSet(['max_per_player' => null])
            ->fillForm(['max_per_player' => 20])->call('save')->assertHasNoFormErrors();
        $this->assertSame(20, $c->refresh()->max_per_person);
        Livewire::test(EditCompetition::class, ['record' => $c->id])->assertFormSet(['max_per_player' => 20])
            ->fillForm(['max_per_player' => null])->call('save')->assertHasNoFormErrors();
        $this->assertSame(500, $c->refresh()->max_per_person);
    }

    public function test_the_announcement_bar_shows_the_admins_message_and_only_links_to_this_site(): void
    {
        $this->getJson('/api/site')->assertJsonPath('announcement', null);
        Setting::put('announcement_text', 'Double Draw Sunday');
        Setting::put('announcement_link', '/competitions?sort=closing');
        Cache::flush();
        $this->getJson('/api/site')->assertJsonPath('announcement', ['text' => 'Double Draw Sunday', 'link' => '/competitions?sort=closing']);
        foreach (['https://elsewhere.example', '//elsewhere.example', 'javascript:alert(1)'] as $bad) {
            Setting::put('announcement_link', $bad);
            Cache::flush();
            $this->getJson('/api/site')->assertJsonPath('announcement.link', null);
        }
    }

    public function test_postal_envelopes_import_from_a_csv_each_checked_like_one_logged_by_hand(): void
    {
        $admin = $this->signIn();
        $c = $this->competition('draw');
        $ok = $this->player(['email' => 'post@example.com']);
        $csv = "\xEF\xBB\xBFemail,received_at\npost@example.com,".now('Europe/London')->subHour()->format('d/m/Y H:i')."\nnobody@example.com,".now('Europe/London')->subHour()->format('Y-m-d H:i')."\n,\n";
        $r = app(Postal::class)->importCsv($c, $csv, $admin);
        $this->assertSame(1, $r['entered']);
        $this->assertSame(2, $r['refused']);
        $this->assertSame(['entered', 'refused', 'skipped'], array_column($r['rows'], 'status'));
        $this->assertSame(2, PostalEntry::query()->count());
        $this->assertSame('entered', PostalEntry::query()->where('user_id', $ok->id)->value('status'));

        $this->expectException(\InvalidArgumentException::class);
        app(Postal::class)->importCsv($c, "name,when\nx,y", $admin);
    }

    public function test_instant_prizes_import_from_a_csv_before_publishing_only(): void
    {
        $rows = PrizeTable::parse("Name,Value,Type,Quantity\n£500 Cash,500,cash,1\n£2.50 Credit,2.50,site credit,40\nAir Fryer,\"1,099.00\",physical,2\n");
        $this->assertSame([
            ['name' => '£500 Cash', 'value_pence' => 50000, 'type' => 'cash', 'quantity' => 1],
            ['name' => '£2.50 Credit', 'value_pence' => 250, 'type' => 'site_credit', 'quantity' => 40],
            ['name' => 'Air Fryer', 'value_pence' => 109900, 'type' => 'physical', 'quantity' => 2],
        ], $rows);
        foreach (["name,value,type,quantity\n,5,cash,1", "name,value,type,quantity\nX,five,cash,1", "name,value,type,quantity\nX,5,car,1", "name,value,type,quantity\nX,5,cash,0"] as $bad) {
            try {
                PrizeTable::parse($bad);
                $this->fail('Accepted: '.$bad);
            } catch (\InvalidArgumentException) {
            }
        }

        $draft = $this->competition('instant', ['max_tickets' => 100, 'max_per_person' => 100], publish: false);
        PrizeTable::replace($draft, $rows);
        $this->assertSame(['£500 Cash', '£2.50 Credit', 'Air Fryer'], $draft->prizes()->pluck('name')->all());
        try {
            PrizeTable::replace($draft, [['name' => 'Too Many', 'value_pence' => 100, 'type' => 'cash', 'quantity' => 101]]);
            $this->fail('More prizes than tickets');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        PrizeTable::replace($this->competition('instant'), $rows);
    }

    public function test_winner_photos_show_only_with_consent_and_videos_only_over_https(): void
    {
        $c = $this->competition('draw', ['closes_at' => now()->addMinutes(30), 'max_per_person' => 1000]);
        $u = $this->player();
        $this->topUp($u, 500);
        $this->buy($u, $c, 4)->assertSuccessful();
        Carbon::setTestNow(now()->addMinutes(31));
        Verity::close($c->verity_ref);
        Carbon::setTestNow(now()->addMinutes(5));
        Verity::draw($c->verity_ref);
        $w = Winner::query()->where('type', 'main')->firstOrFail();

        $this->signIn();
        Livewire::test(WinnerPhotos::class)->callTableAction('media', $w, ['photo_path' => null, 'video_url' => 'https://www.facebook.com/watch/?v=1', 'consent' => false])
            ->assertHasNoTableActionErrors();
        WinnerMedia::query()->where('verity_winner_id', $w->id)->update(['photo_path' => 'winners/a.jpg']);
        $this->getJson('/api/winners/draws')->assertJsonPath('data.0.photoUrl', null)->assertJsonPath('data.0.videoUrl', 'https://www.facebook.com/watch/?v=1');
        WinnerMedia::query()->where('verity_winner_id', $w->id)->update(['consent' => true, 'video_url' => 'http://insecure.example']);
        $this->getJson('/api/winners/draws')->assertJsonPath('data.0.photoUrl', '/storage/winners/a.jpg')->assertJsonPath('data.0.videoUrl', null);
    }

    public function test_the_live_draw_console_draws_through_verity_and_reveals_the_winner(): void
    {
        $c = $this->competition('draw', ['closes_at' => now()->addMinutes(30), 'max_per_person' => 1000]);
        $u = $this->player(['first_name' => 'Rosa', 'last_name' => 'Diaz']);
        $this->topUp($u, 500);
        $this->buy($u, $c, 4)->assertSuccessful();

        $this->signIn('manager');
        Livewire::test(LiveDraw::class)->assertTableActionHidden('draw', $c);
        Carbon::setTestNow(now()->addMinutes(31));
        Verity::close($c->verity_ref);
        Livewire::test(LiveDraw::class)->assertTableActionVisible('draw', $c);
        Carbon::setTestNow(now()->addMinutes(5));
        Livewire::test(LiveDraw::class)->callTableAction('draw', $c)->assertNotified('Drawn');

        $w = Winner::query()->where('type', 'main')->with('entry')->firstOrFail();
        $this->assertSame($u->ownerRef(), $w->owner_ref);
        $this->assertSame(1, AdminAction::query()->where('action', 'competition.drawn_live')->count());
        Livewire::test(LiveDraw::class)->assertTableActionVisible('reveal', $c);
        $this->get(route('admin.live-draw.reveal', $c))->assertOk()->assertSee('Rosa D.')->assertSee((string) $w->entry->number)->assertSee('/verify/'.$c->verity_ref);

        $this->signIn('support');
        $this->get(route('admin.live-draw.reveal', $c))->assertForbidden();
    }

    public function test_the_admin_pages_take_the_csv_files_and_delete_them_afterwards(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('competitions/x.webp', 'img');
        $this->signIn();
        $c = $this->competition('draw');
        $this->player(['email' => 'post@example.com']);
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('envelopes.csv', "email,received_at\npost@example.com,".now('Europe/London')->subHour()->format('d/m/Y H:i')."\n");
        Livewire::test(\App\Filament\Pages\PostalEntries::class)->callAction('import', ['competition_id' => $c->id, 'file' => $file])
            ->assertHasNoActionErrors()->assertNotified('1 entered, 0 refused');
        $this->assertSame('entered', PostalEntry::query()->value('status'));
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->allFiles('imports'));

        $draft = $this->competition('instant', ['image' => 'competitions/x.webp', 'max_tickets' => 100, 'max_per_person' => 100], publish: false);
        $prizes = \Illuminate\Http\UploadedFile::fake()->createWithContent('prizes.csv', "name,value,type,quantity\n£10 Credit,10,site_credit,5\n");
        Livewire::test(EditCompetition::class, ['record' => $draft->id])->callAction('importPrizes', ['file' => $prizes])->assertNotified('1 prize imported');
        $this->assertSame([['£10 Credit', 1000, 5]], $draft->prizes()->get()->map(fn ($p) => [$p->name, $p->value_pence, $p->quantity])->all());
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->allFiles('imports'));
    }

    public function test_an_admin_exports_a_players_personal_data_for_a_subject_access_request(): void
    {
        $u = $this->player(['first_name' => 'Rosa', 'email' => 'rosa@example.com']);
        $this->topUp($u, 500);
        \App\Models\ContactMessage::query()->create(['name' => 'Rosa', 'email' => 'rosa@example.com', 'topic' => 'other', 'message' => 'Hello']);
        $data = \App\Support\PersonalData::export($u);
        $this->assertSame('rosa@example.com', $data['account']['email']);
        $this->assertArrayNotHasKey('password', $data['account']);
        $this->assertArrayNotHasKey('remember_token', $data['account']);
        $this->assertIsArray($data['orders']);
        $this->assertNotEmpty($data['wallet_ledger']);
        $this->assertSame('Hello', $data['contact_messages'][0]['message']);

        $this->signIn('support');
        Livewire::test(ListUsers::class)->callTableAction('exportData', $u, ['reason' => 'SAR by email'])->assertFileDownloaded('personal-data-'.$u->id.'-'.now()->format('Y-m-d').'.json');
        $this->assertSame('SAR by email', AdminAction::query()->where('action', 'player.data_exported')->value('reason'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\PostalEntry;
use App\Models\PostalReference;
use App\Services\Postal;
use Tests\TestCase;

/** Amendment 7: postal entries matched automatically by the reference the entrant writes on them. */
class PostalReferencesTest extends TestCase
{
    private function provider(array $entries, string $token = 'provider-secret-token-123')
    {
        return $this->withToken($token)->postJson('/api/webhooks/postal', ['entries' => $entries]);
    }

    public function test_a_player_gets_one_reference_per_competition(): void
    {
        $c = $this->competition('draw');
        $other = $this->competition('draw');
        $u = $this->player();
        $a = $this->actingAs($u)->postJson('/api/me/postal-references', ['competitionId' => $c->id])->assertOk()->json('reference');
        $this->assertMatchesRegularExpression('/^PE[2-9A-HJKMNP-Z]{2} [2-9A-HJKMNP-Z]{4} [2-9A-HJKMNP-Z]{2}$/', $a);
        $this->assertSame($a, $this->postJson('/api/me/postal-references', ['competitionId' => $c->id])->json('reference'));
        $this->assertNotSame($a, $this->postJson('/api/me/postal-references', ['competitionId' => $other->id])->json('reference'));
        $this->postJson('/api/me/postal-references', ['competitionId' => $this->competition('draw', [], [], false)->id])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/me/postal-references', ['competitionId' => $c->id])->assertUnauthorized();
    }

    public function test_the_provider_submits_references_and_entries_are_matched_once(): void
    {
        config(['services.postal.provider_token' => 'provider-secret-token-123']);
        $c = $this->competition('draw');
        $u = $this->player();
        $code = PostalReference::for($u, $c)->code;
        $at = now()->subHour()->toIso8601ZuluString();

        $this->provider([['id' => 'env-1', 'reference' => strtolower(PostalReference::pretty($code)), 'receivedAt' => $at]], 'wrong')->assertUnauthorized();
        $res = $this->provider([
            ['id' => 'env-1', 'reference' => strtolower(PostalReference::pretty($code)), 'receivedAt' => $at],
            ['id' => 'env-2', 'reference' => 'PEZZZZZZZZ', 'receivedAt' => $at],
        ])->assertOk()->json('results');
        $this->assertSame(['entered', 'unmatched'], array_column($res, 'status'));
        $again = $this->provider([['id' => 'env-1', 'reference' => $code, 'receivedAt' => $at]])->json('results.0');
        $this->assertSame(['entered', true], [$again['status'], $again['duplicate']]);
        $p = PostalEntry::query()->sole();
        $this->assertSame([$u->id, 'provider', $code, null], [$p->user_id, $p->source, $p->reference, $p->logged_by]);
        $this->assertSame(1, app(\App\Services\CompetitionState::class)->held($c->refresh(), $u->ownerRef()));
    }

    public function test_the_same_checks_apply_as_a_hand_logged_envelope(): void
    {
        config(['services.postal.provider_token' => 'provider-secret-token-123']);
        $c = $this->competition('draw', ['closes_at' => now()->addHour()]);
        $u = $this->player();
        $code = PostalReference::for($u, $c)->code;
        $u->forceFill(['self_excluded_until' => now()->addMonths(6)])->save();
        $this->assertSame('refused', $this->provider([['id' => 'x1', 'reference' => $code, 'receivedAt' => now()->subMinute()->toIso8601ZuluString()]])->json('results.0.status'));
        $u->forceFill(['self_excluded_until' => null])->save();
        $this->assertSame('refused', $this->provider([['id' => 'x2', 'reference' => $code, 'receivedAt' => now()->addHours(2)->toIso8601ZuluString()]])->json('results.0.status'));
    }

    public function test_an_admin_csv_can_match_by_reference_without_choosing_the_competition(): void
    {
        $admin = $this->admin();
        $c = $this->competition('draw');
        $u = $this->player();
        $code = PostalReference::for($u, $c)->code;
        $r = app(Postal::class)->importCsv(null, "reference,received_at,id\n".PostalReference::pretty($code).','.now('Europe/London')->subHour()->format('d/m/Y H:i').",A1\nNOPE,".now('Europe/London')->format('d/m/Y').",A2\n", $admin);
        $this->assertSame([1, 1], [$r['entered'], $r['refused']]);
        $this->assertSame('csv', PostalEntry::query()->value('source'));

        $this->expectException(\InvalidArgumentException::class);
        app(Postal::class)->importCsv(null, "email,received_at\na@b.c,21/11/2026", $admin);
    }

    public function test_the_provider_route_is_off_until_a_token_is_set(): void
    {
        config(['services.postal.provider_token' => null]);
        $this->provider([['id' => 'a', 'reference' => 'PE', 'receivedAt' => now()->toIso8601ZuluString()]], '')->assertNotFound();
    }
}

<?php

namespace Tests\Feature;

use App\Services\Publishing;
use App\Support\GameIds;
use Tests\TestCase;

/** Game skins (shared/game-ids.json): presentation only, known values only. */
class GameSkinsTest extends TestCase
{
    public function test_the_classic_skin_is_the_only_one_and_is_returned_by_the_api(): void
    {
        // Vault Nine starts with the classic look only; the old site's themed skins are refused.
        $this->assertSame(['classic'], GameIds::skins());
        $c = $this->competition('instant', ['skin' => 'classic', 'game' => 'gems']);
        $this->getJson('/api/competitions/'.$c->slug)->assertOk()->assertJsonPath('skin', 'classic')->assertJsonPath('game', 'gems');
        foreach (['christmas', 'pirates', 'cash_escape', 'hip_hop', 'smash_cash'] as $old) {
            try {
                $this->competition('instant', ['skin' => $old], [], false);
                $this->fail("{$old} was saved");
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_competitions_default_to_classic_and_refuse_unknown_skins_and_games(): void
    {
        $c = $this->competition('instant', [], [], false);
        $this->assertSame('classic', $c->refresh()->skin);

        $this->expectException(\DomainException::class);
        $c->forceFill(['skin' => 'easter'])->save();
    }

    public function test_an_unknown_game_is_refused(): void
    {
        $c = $this->competition('instant', [], [], false);
        $this->expectException(\DomainException::class);
        $c->forceFill(['game' => 'roulette'])->save();
    }

    public function test_a_classic_competition_publishes_with_any_game(): void
    {
        $c = $this->competition('instant', ['skin' => 'classic', 'game' => 'slots'], [], false);
        $this->assertSame([], app(Publishing::class)->problems($c));
    }

    public function test_nothing_that_decides_prices_pays_or_allocates_reads_the_skin(): void
    {
        // Publishing only checks the value is allowed; Reveal only passes it to the game. Nothing else may read it.
        $allowed = ['Publishing.php', 'Reveal.php'];
        foreach (glob(app_path('Services/*.php')) as $file) {
            if (in_array(basename($file), $allowed, true)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/\bskin\b/i', (string) file_get_contents($file), basename($file).' reads the game skin');
        }
    }
}

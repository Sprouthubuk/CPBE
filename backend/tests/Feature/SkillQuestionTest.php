<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Publishing;
use Illuminate\Support\Str;
use Tests\TestCase;

/** SOW 2.2: a skill question on every paid entry; the right answer never leaves the server. */
class SkillQuestionTest extends TestCase
{
    private function withQuestion(array $attrs = []): Competition
    {
        return $this->competition('draw', $attrs + ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000,
            'skill_question' => 'What is 2 + 3?', 'skill_options' => ['4', '5', '6'], 'skill_answer' => 1]);
    }

    private function checkout($u, Competition $c, ?int $answer)
    {
        $line = ['competitionId' => $c->id, 'quantity' => 2] + ($answer === null ? [] : ['skillAnswer' => $answer]);

        return $this->actingAs($u)->postJson('/api/checkout', ['lines' => [$line], 'payWith' => 'wallet', 'useWallet' => true, 'requestId' => (string) Str::uuid()]);
    }

    public function test_publishing_needs_a_skill_question(): void
    {
        Setting::put('require_skill_question', '1');
        $c = $this->competition('draw', [], [], false);
        $this->assertContains('Add the skill question every paid entry must answer.', app(Publishing::class)->problems($c));
        $this->withQuestion(); // publishes fine
        $this->assertSame(1, Competition::query()->whereNotNull('verity_ref')->count());
    }

    public function test_a_question_needs_two_to_five_answers_and_the_right_one(): void
    {
        $this->expectException(\DomainException::class);
        $this->competition('draw', ['skill_question' => 'Pick one', 'skill_options' => ['Only'], 'skill_answer' => 0], [], false);
    }

    public function test_the_basket_shows_the_question_but_never_the_answer(): void
    {
        $c = $this->withQuestion();
        $p = $this->postJson('/api/basket/price', ['lines' => [['competitionId' => $c->id, 'quantity' => 2]]])->assertOk();
        $this->assertSame(['question' => 'What is 2 + 3?', 'options' => ['4', '5', '6']], $p->json('lines.0.skillQuestion'));
        $this->assertStringNotContainsString('skill_answer', $p->getContent());
        $this->assertStringNotContainsString('skill_answer', $this->getJson('/api/competitions/'.$c->slug)->getContent());
    }

    public function test_checkout_needs_the_right_answer_and_keeps_it(): void
    {
        $c = $this->withQuestion();
        $u = $this->player();
        $this->topUp($u, 1000);
        $this->checkout($u, $c, null)->assertStatus(422)->assertJsonPath('code', 'skill_answer_missing');
        $this->checkout($u, $c, 0)->assertStatus(422)->assertJsonPath('code', 'skill_answer_wrong');
        $this->assertSame(1000, $this->wallet()->balances($u)['deposit'], 'nothing taken');
        $this->assertSame(0, Order::query()->count());
        $this->checkout($u, $c, 1)->assertCreated();
        $this->assertSame(1, Order::query()->firstOrFail()->lines()->value('skill_answer'));
    }

    public function test_competitions_without_a_question_need_no_answer(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $u = $this->player();
        $this->topUp($u, 1000);
        $this->checkout($u, $c, null)->assertCreated();
    }
}

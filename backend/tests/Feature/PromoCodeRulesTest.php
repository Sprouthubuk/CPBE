<?php

namespace Tests\Feature;

use App\Models\BundleTier;
use App\Models\PromoCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** SOW 2.3: discount codes as a fixed amount or a percentage, with use limits, dates and a minimum spend. */
class PromoCodeRulesTest extends TestCase
{
    private function price($u, array $lines, string $code): array
    {
        return ($u ? $this->actingAs($u) : $this)->postJson('/api/basket/price', ['lines' => $lines, 'promoCode' => $code])->assertOk()->json();
    }

    public function test_a_fixed_amount_code_takes_its_amount_off_but_never_more_than_half(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        $p = PromoCode::query()->create(['code' => 'fiver', 'discount_type' => 'fixed', 'amount_off_pence' => 500, 'active' => true]);
        $this->assertSame('FIVER', $p->code);
        $this->assertNull($p->percent_off);

        $big = $this->price($this->player(), [['competitionId' => $c->id, 'quantity' => 20]], 'fiver');
        $this->assertSame(['code' => 'FIVER', 'discountPence' => 500, 'label' => '£5.00 off'], $big['promo']);
        $this->assertSame(1500, $big['totalPence']);

        $small = $this->price($this->player(), [['competitionId' => $c->id, 'quantity' => 4]], 'FIVER');
        $this->assertSame(200, $small['promo']['discountPence']);
    }

    public function test_a_percentage_code_shows_its_label(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        PromoCode::query()->create(['code' => 'TEN', 'percent_off' => 10, 'active' => true]);
        $r = $this->price(null, [['competitionId' => $c->id, 'quantity' => 10]], 'ten');
        $this->assertSame(['code' => 'TEN', 'discountPence' => 100, 'label' => '10% off'], $r['promo']);
    }

    public function test_a_minimum_spend_is_checked_against_the_basket(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        PromoCode::query()->create(['code' => 'SPEND10', 'percent_off' => 20, 'min_spend_pence' => 1000, 'active' => true]);
        $under = $this->price(null, [['competitionId' => $c->id, 'quantity' => 9]], 'SPEND10');
        $this->assertNull($under['promo']);
        $this->assertContains('Spend £10.00 or more to use that promo code.', $under['problems']);
        $this->assertSame(200, $this->price(null, [['competitionId' => $c->id, 'quantity' => 10]], 'SPEND10')['promo']['discountPence']);
    }

    public function test_a_code_only_works_between_its_start_and_expiry(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        PromoCode::query()->create(['code' => 'LATER', 'percent_off' => 10, 'starts_at' => now()->addDay(), 'expires_at' => now()->addDays(3), 'active' => true]);
        $line = [['competitionId' => $c->id, 'quantity' => 10]];
        $this->assertContains("That promo code isn't valid.", $this->price(null, $line, 'LATER')['problems']);
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(100, $this->price(null, $line, 'LATER')['promo']['discountPence']);
        Carbon::setTestNow(now()->addDays(2));
        $this->assertNull($this->price(null, $line, 'LATER')['promo']);
    }

    public function test_a_code_can_end_only_after_it_starts(): void
    {
        $this->expectException(\DomainException::class);
        PromoCode::query()->create(['code' => 'BAD', 'percent_off' => 10, 'starts_at' => now()->addDay(), 'expires_at' => now(), 'active' => true]);
    }

    public function test_the_per_player_limit_counts_that_players_orders(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000]);
        PromoCode::query()->create(['code' => 'ONCE', 'percent_off' => 10, 'max_uses_per_player' => 1, 'active' => true]);
        $a = $this->player();
        $this->topUp($a, 5000);
        $this->actingAs($a)->postJson('/api/checkout', ['lines' => [['competitionId' => $c->id, 'quantity' => 10]], 'payWith' => 'wallet',
            'useWallet' => true, 'promoCode' => 'ONCE', 'requestId' => (string) Str::uuid()])->assertCreated();
        $again = $this->price($a, [['competitionId' => $c->id, 'quantity' => 10]], 'ONCE');
        $this->assertNull($again['promo']);
        $this->assertContains('You’ve already used that promo code.', $again['problems']);
        // Someone else can still use it, and the total count went up once.
        $this->assertSame(100, $this->price($this->player(), [['competitionId' => $c->id, 'quantity' => 10]], 'ONCE')['promo']['discountPence']);
        $this->assertSame(1, PromoCode::query()->where('code', 'ONCE')->value('uses'));
    }

    public function test_a_code_can_include_bundles(): void
    {
        $c = $this->competition('draw', ['price_pence' => 100, 'max_tickets' => 1000, 'max_per_person' => 1000, 'bundles_enabled' => true]);
        $tier = BundleTier::query()->where('paid_qty', 5)->where('free_qty', 5)->firstOrFail();
        $c->bundleTiers()->attach($tier->id, ['is_featured' => true]);
        PromoCode::query()->create(['code' => 'ALL', 'percent_off' => 10, 'include_bundles' => true, 'active' => true]);
        $r = $this->price(null, [['competitionId' => $c->id, 'bundleId' => $tier->id]], 'ALL');
        $this->assertSame(50, $r['promo']['discountPence']);
    }

    public function test_a_fixed_code_needs_an_amount(): void
    {
        $this->expectException(\DomainException::class);
        PromoCode::query()->create(['code' => 'NONE', 'discount_type' => 'fixed', 'active' => true]);
    }
}

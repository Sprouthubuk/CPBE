<?php

namespace Database\Seeders;

use App\Models\BundleTier;
use App\Models\Competition;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\Publishing;
use App\Services\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Sample data for a developer's machine and the full-stack tests. Refuses to run in production:
 * the live database starts empty of Verity data (Verity's go-live checks fail otherwise).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Demo data is never seeded in production.');
        }
        Setting::put('sales_open', '1');
        // Demo competitions: most have no skill question so the browser tests can buy quickly; one has one (below).
        Setting::put('require_skill_question', '0');

        $admin = User::query()->firstOrNew(['email' => 'admin@example.com']);
        $admin->forceFill(['first_name' => 'Sam', 'last_name' => 'Admin', 'password' => 'admin-password-123', 'email_verified_at' => now(), 'is_admin' => true, 'is_staff' => true])->save();

        $player = User::query()->firstOrNew(['email' => 'player@example.com']);
        $player->forceFill(['first_name' => 'Liam', 'last_name' => 'Player', 'password' => 'password123', 'email_verified_at' => now(), 'date_of_birth' => '1990-01-01'])->save();
        app(Wallet::class)->credit($player, 'deposit', 1250, 'Sample top up', 'demo:player:deposit');

        $images = base_path('../frontend/mock-api/images');
        foreach (glob($images.'/*.webp') ?: [] as $f) {
            Storage::disk('public')->put('competitions/'.basename($f), file_get_contents($f));
        }

        $make = function (array $attrs, array $prizes = []) use ($admin): void {
            if (Competition::query()->where('slug', $attrs['slug'])->exists()) {
                return;
            }
            $c = Competition::query()->create($attrs + ['price_pence' => 25, 'max_tickets' => 5000, 'max_per_person' => 500, 'closes_at' => now()->addDays(10)]);
            foreach ($prizes as $i => [$name, $value, $qty, $type]) {
                $c->prizes()->create(['name' => $name, 'value_pence' => $value, 'quantity' => $qty, 'type' => $type, 'sort' => $i]);
            }
            if ($c->bundles_enabled) { // the first three bundle tiers, Buy 10 Get 15 Free as Our Pick
                $c->bundleTiers()->sync(BundleTier::query()->orderBy('sort_order')->take(3)->get()->mapWithKeys(fn ($t) => [$t->id => ['is_featured' => $t->paid_qty === 10]])->all());
            }
            app(Publishing::class)->publish($c, $admin);
        };
        $make(['slug' => 'treble-twenty-cash-claw', 'title' => 'Treble Twenty Cash Claw', 'type' => 'instant', 'game' => 'balloon_pop', 'image' => 'competitions/claw.webp', 'featured' => true,
            'presets' => [5, 10, 20, 50, 100, 200, 500], 'popular_preset' => 20, 'min_tickets' => 4,
            'end_prize_name' => 'Treble Top Jackpot', 'end_prize_value_pence' => 5000, 'end_prize_count' => 2, 'end_prize_type' => 'site_credit',
            'description' => 'Every ticket plays one game. Your result is fixed when you buy: the game just reveals it.'],
            [['£500 Cash', 50000, 1, 'cash'], ['£5 Site Credit', 500, 100, 'site_credit'], ['50p Site Credit', 50, 1500, 'site_credit']]);
        $make(['slug' => 'cash-flights', 'title' => 'Cash Flights', 'type' => 'instant', 'game' => 'scratch_card', 'image' => 'competitions/cash.webp', 'price_pence' => 10],
            [['£50 Cash', 5000, 2, 'cash'], ['20p Site Credit', 20, 1000, 'site_credit']]);
        // A competition with a skill question (SOW 2.2), answered at checkout.
        $make(['slug' => 'games-console-bundle', 'title' => 'Games Console Bundle', 'type' => 'draw', 'image' => 'competitions/board.webp', 'price_pence' => 99,
            'end_prize_name' => 'Games Console Bundle', 'end_prize_value_pence' => 50000, 'max_tickets' => 2000, 'max_per_person' => 2000, 'tags' => ['tech'],
            'skill_question' => 'How many sides does a triangle have?', 'skill_options' => ['Two', 'Three', 'Four'], 'skill_answer' => 1]);
        $make(['slug' => 'pro-dartboard-bundle', 'title' => 'Pro Dartboard Bundle', 'type' => 'draw', 'image' => 'competitions/board.webp', 'price_pence' => 49,
            'end_prize_name' => 'Pro Dartboard & Surround', 'end_prize_value_pence' => 6000, 'cash_alternative_pence' => 5000, 'max_tickets' => 999, 'max_per_person' => 999, 'bundles_enabled' => true, 'max_bundle_share_pct' => 10, 'prize_cost_pence' => 4000]);


        // Sample loyalty programme and membership tiers (SOW 2.11, 2.12), so their pages have something to show.
        if (! \App\Models\LoyaltyProgramme::query()->exists()) {
            $club = \App\Models\LoyaltyProgramme::query()->create(['name' => 'Rewards Club', 'active' => true, 'points_per_pound' => 10, 'expiry_days' => 365,
                'description' => 'Earn points on every order and swap them for credit and free entries.']);
            $club->tiers()->createMany([['name' => 'Bronze', 'threshold_points' => 0, 'multiplier_pct' => 100, 'benefits' => 'Standard points'],
                ['name' => 'Silver', 'threshold_points' => 2000, 'multiplier_pct' => 125, 'benefits' => '1.25x points'],
                ['name' => 'Gold', 'threshold_points' => 10000, 'multiplier_pct' => 150, 'benefits' => '1.5x points']]);
            $club->rewards()->createMany([['name' => '£1 Site Credit', 'type' => 'site_credit', 'points_cost' => 500, 'credit_pence' => 100],
                ['name' => '5 Free Entries', 'type' => 'free_entries', 'points_cost' => 1500, 'tickets' => 5]]);
            $club->bonusEvents()->create(['kind' => 'signup', 'points' => 100]);
        }
        if (! \App\Models\MembershipTier::query()->exists()) {
            \App\Models\MembershipTier::query()->create(['name' => 'Silver', 'price_pence' => 999, 'active' => true, 'sort' => 1,
                'monthly_free_tickets' => 10, 'monthly_bonus_credit_pence' => 200, 'loyalty_bonus_pct' => 25, 'description' => 'Free entries and credit every month.']);
            \App\Models\MembershipTier::query()->create(['name' => 'Gold', 'price_pence' => 1999, 'active' => true, 'sort' => 2,
                'monthly_free_tickets' => 25, 'monthly_bonus_credit_pence' => 500, 'early_access_hours' => 24, 'loyalty_bonus_pct' => 50]);
        }

        foreach (['terms' => 'Terms & Conditions', 'privacy' => 'Privacy Policy', 'cookies' => 'Cookie Policy', 'free-entry' => 'Free Entry Terms', 'complaints' => 'Complaints'] as $slug => $title) {
            Page::query()->firstOrCreate(['slug' => $slug], ['title' => $title, 'version' => 'sample',
                'html' => '<h2>Sample wording</h2><p>This is placeholder text for a developer’s machine. The real wording is entered in the admin.</p>']);
        }
    }
}

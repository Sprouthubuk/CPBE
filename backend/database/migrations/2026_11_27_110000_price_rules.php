<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amendment 5: rules set in advance that change a competition's price, bonus tickets, bundles on offer and
 * (live draws) how many tickets are on sale, by time left, tickets sold or demand. The maximum number of
 * tickets stays as published to Verity; a release rule only holds some back for later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_price_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('competition_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);                       // shown to players, e.g. "Early Bird"
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);  // first matching rule wins, per setting
            $t->string('trigger', 32);                    // always | hours_left_more | hours_left_at_most | sold_pct_below | sold_pct_at_least | sold_24h_at_least
            $t->unsignedInteger('trigger_value')->nullable();
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->unsignedInteger('price_pence')->nullable();   // ticket price while the rule applies
            $t->unsignedSmallInteger('bonus_buy')->nullable(); // buy X …
            $t->unsignedSmallInteger('bonus_get')->nullable(); // … get Y free
            $t->json('bundle_tier_ids')->nullable();          // bundles on offer while it applies (null = all)
            $t->unsignedTinyInteger('release_pct')->nullable(); // live draws: % of tickets on sale so far
            $t->timestamps();
            $t->index(['competition_id', 'is_active', 'sort']);
        });
        Schema::table('order_lines', fn (Blueprint $t) => $t->foreignId('price_rule_id')->nullable()->after('source'));
    }

    public function down(): void
    {
        Schema::table('order_lines', fn (Blueprint $t) => $t->dropColumn('price_rule_id'));
        Schema::dropIfExists('competition_price_rules');
    }
};

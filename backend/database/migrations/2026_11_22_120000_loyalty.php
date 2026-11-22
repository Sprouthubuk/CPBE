<?php

use App\Support\LedgerGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SOW 2.11: loyalty programmes with earn rates, bonus events, tiers, points expiry and rewards.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programmes', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->text('description')->nullable();
            $t->boolean('active')->default(false);
            $t->unsignedInteger('points_per_pound')->default(1);   // points per £1 of real money spent on tickets
            $t->unsignedInteger('expiry_days')->nullable();        // points expire this many days after they're earned; null = never
            $t->timestamps();
        });
        Schema::create('loyalty_tiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('loyalty_programme_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->unsignedInteger('threshold_points')->default(0);   // lifetime points earned to reach it
            $t->unsignedSmallInteger('multiplier_pct')->default(100); // 150 = earn 1.5x
            $t->text('benefits')->nullable();
            $t->timestamps();
        });
        Schema::create('loyalty_bonus_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('loyalty_programme_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);                                 // signup | birthday | first_order | competition
            $t->foreignUlid('competition_id')->nullable()->constrained();
            $t->unsignedInteger('points')->default(0);              // flat bonus
            $t->unsignedSmallInteger('multiplier_pct')->default(100); // competition: earn N% on that competition's spend
            $t->boolean('active')->default(true);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamps();
        });
        Schema::create('loyalty_rewards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('loyalty_programme_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->text('description')->nullable();
            $t->string('type', 20);                                 // site_credit | free_entries | bonus_entries | custom
            $t->unsignedInteger('points_cost');
            $t->unsignedInteger('credit_pence')->nullable();        // site_credit
            $t->unsignedInteger('tickets')->nullable();             // free_entries / bonus_entries
            $t->foreignUlid('competition_id')->nullable()->constrained(); // bonus_entries: this draw only
            $t->unsignedInteger('stock')->nullable();               // null = unlimited
            $t->foreignId('min_tier_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('loyalty_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('loyalty_programme_id')->constrained();
            $t->integer('points');                                  // + earned, − spent / expired / reversed
            $t->string('kind', 12);                                 // earn | bonus | redeem | expire | reverse | adjust | refund
            $t->string('reason', 160);
            $t->string('reference', 120)->unique();                // the same event never counts twice
            $t->string('order_id', 26)->nullable()->index();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'loyalty_programme_id']);
        });
        Schema::create('loyalty_redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('loyalty_reward_id')->constrained();
            $t->unsignedInteger('points');
            $t->string('status', 12);                               // done | pending | fulfilled | cancelled
            $t->foreignId('voucher_id')->nullable();
            $t->text('note')->nullable();
            $t->timestamp('fulfilled_at')->nullable();
            $t->timestamps();
        });
        Schema::table('vouchers', function (Blueprint $t) {
            $t->string('source', 12)->default('referral')->after('referral_id'); // referral | loyalty | membership
            $t->foreignUlid('for_competition_id')->nullable()->after('source')->constrained('competitions'); // only this draw
        });
        LedgerGuards::install();
    }

    public function down(): void
    {
        LedgerGuards::uninstall();
        Schema::table('vouchers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('for_competition_id');
            $t->dropColumn('source');
        });
        foreach (['loyalty_redemptions', 'loyalty_entries', 'loyalty_rewards', 'loyalty_bonus_events', 'loyalty_tiers', 'loyalty_programmes'] as $t) {
            Schema::dropIfExists($t);
        }
        LedgerGuards::install();
    }
};

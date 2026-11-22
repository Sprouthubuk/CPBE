<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SOW 2.12: monthly membership tiers with perks and recurring billing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_tiers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60);
            $t->text('description')->nullable();
            $t->unsignedInteger('price_pence');                     // a month
            $t->boolean('active')->default(false);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->string('eligibility', 20)->default('anyone');       // anyone | id_verified | min_spend
            $t->unsignedInteger('min_spend_pence')->default(0);     // min_spend: lifetime real-money spend
            $t->unsignedInteger('max_members')->nullable();
            $t->unsignedInteger('monthly_free_tickets')->default(0); // a voucher each month
            $t->unsignedInteger('monthly_bonus_credit_pence')->default(0);
            $t->unsignedSmallInteger('early_access_hours')->default(0);
            $t->unsignedSmallInteger('loyalty_bonus_pct')->default(0); // +N% loyalty points
            $t->text('perks')->nullable();                          // anything else, in words
            $t->timestamps();
        });
        Schema::create('memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('membership_tier_id')->constrained();
            $t->foreignId('pending_tier_id')->nullable()->constrained('membership_tiers'); // a downgrade at the next renewal
            $t->string('status', 12);                               // pending | active | past_due | cancelled | lapsed
            $t->string('pay_with', 8)->default('card');             // card | wallet
            $t->timestamp('started_at')->nullable();
            $t->timestamp('current_period_start')->nullable();
            $t->timestamp('current_period_end')->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
            $t->string('gateway_parent_ref', 64)->nullable();       // the card payment renewals are charged against
            $t->unsignedTinyInteger('failed_attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->string('end_reason', 120)->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
            $t->index(['status', 'current_period_end']);
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('membership_id')->nullable()->constrained();
            $t->string('membership_kind', 10)->nullable();          // join | renewal | upgrade | resume
            $t->foreignId('membership_tier_id')->nullable()->constrained(); // the tier this payment is for
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('membership_tier_id');
            $t->dropConstrainedForeignId('membership_id');
            $t->dropColumn('membership_kind');
        });
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_tiers');
    }
};

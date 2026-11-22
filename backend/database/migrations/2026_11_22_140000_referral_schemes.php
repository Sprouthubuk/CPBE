<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SOW 2.13: referral schemes the Client creates and switches on and off, with multi-level rewards.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_schemes', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->boolean('active')->default(false);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->string('who_can_refer', 12)->default('all');        // all | members
            $t->string('qualifying', 12)->default('first_order');   // first_order | any_order | membership
            $t->unsignedInteger('min_spend_pence')->default(0);
            $t->string('referrer_reward_type', 8)->default('tickets'); // credit | tickets | points | none
            $t->unsignedInteger('referrer_reward_amount')->default(0); // pence, tickets or points
            $t->string('friend_reward_type', 8)->default('tickets');
            $t->unsignedInteger('friend_reward_amount')->default(0);
            $t->foreignId('loyalty_programme_id')->nullable()->constrained()->nullOnDelete(); // for points rewards
            $t->json('levels')->nullable();                           // [{type, amount}] for level 2, 3, …
            $t->unsignedInteger('monthly_cap')->nullable();           // rewards a referrer gets a month
            $t->unsignedInteger('total_cap')->nullable();             // rewarded referrals for the whole scheme
            $t->timestamps();
        });
        Schema::table('referrals', function (Blueprint $t) {
            $t->foreignId('referral_scheme_id')->nullable()->constrained();
        });
        Schema::create('referral_rewards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('referral_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->unsignedTinyInteger('level');                         // 0 friend, 1 referrer, 2+ upline
            $t->string('type', 8);
            $t->unsignedInteger('amount');
            $t->string('status', 10)->default('given');               // given | reversed
            $t->foreignId('voucher_id')->nullable();
            $t->timestamps();
            $t->unique(['referral_id', 'user_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
        Schema::table('referrals', fn (Blueprint $t) => $t->dropConstrainedForeignId('referral_scheme_id'));
        Schema::dropIfExists('referral_schemes');
    }
};

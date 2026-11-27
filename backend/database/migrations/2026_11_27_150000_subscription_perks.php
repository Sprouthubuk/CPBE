<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Amendment 4: subscriptions can also give a discount on tickets and access to members-only competitions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_tiers', fn (Blueprint $t) => $t->unsignedTinyInteger('ticket_discount_pct')->default(0)->after('loyalty_bonus_pct'));
        // Paid entry only for members of these tiers (null = everyone). The free postal route stays open to all.
        Schema::table('competitions', fn (Blueprint $t) => $t->json('members_only_tier_ids')->nullable());
    }

    public function down(): void
    {
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn('members_only_tier_ids'));
        Schema::table('membership_tiers', fn (Blueprint $t) => $t->dropColumn('ticket_discount_pct'));
    }
};

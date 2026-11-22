<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SOW 2.8: daily and monthly limits beside the weekly one. Lowering applies at once; raising or removing waits 24 hours.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spend_limits', function (Blueprint $t) {
            $t->unsignedInteger('daily_deposit_pence')->nullable();
            $t->unsignedInteger('monthly_deposit_pence')->nullable();
            $t->json('pending_limits')->nullable(); // { daily?: pence|null, monthly?: pence|null } waiting for pending_applies_at
        });
    }

    public function down(): void
    {
        Schema::table('spend_limits', fn (Blueprint $t) => $t->dropColumn(['daily_deposit_pence', 'monthly_deposit_pence', 'pending_limits']));
    }
};

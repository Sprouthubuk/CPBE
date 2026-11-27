<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Amendment 6: a skill question is an optional setting, switched on per competition, off unless switched on. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', fn (Blueprint $t) => $t->boolean('skill_enabled')->default(false)->after('skill_question'));
        // Competitions that already had a question keep asking it.
        DB::table('competitions')->whereNotNull('skill_question')->where('skill_question', '!=', '')->update(['skill_enabled' => true]);
        DB::table('settings')->where('key', 'require_skill_question')->delete();
    }

    public function down(): void
    {
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn('skill_enabled'));
    }
};

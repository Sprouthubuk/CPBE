<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SOW 2.2: a skill question on every paid entry. The answer is never sent to the site; the player's choice is kept per line.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $t) {
            $t->string('skill_question', 200)->nullable();
            $t->json('skill_options')->nullable();               // 2 to 5 answers
            $t->unsignedTinyInteger('skill_answer')->nullable(); // index of the right one
        });
        Schema::table('order_lines', function (Blueprint $t) {
            $t->unsignedTinyInteger('skill_answer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', fn (Blueprint $t) => $t->dropColumn('skill_answer'));
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn(['skill_question', 'skill_options', 'skill_answer']));
    }
};

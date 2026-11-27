<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Amendment 8: each competition says plainly how to watch the draw and how the winner is announced. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $t) {
            $t->string('watch_info', 200)->nullable();
            $t->string('announce_info', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn(['watch_info', 'announce_info']));
    }
};

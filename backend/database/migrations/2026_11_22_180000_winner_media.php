<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Winner photos and draw videos (SOW 2.9): kept beside Verity's winners, never in its tables. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winner_media', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('verity_winner_id')->unique();
            $t->string('photo_path')->nullable();
            $t->string('video_url', 300)->nullable();
            $t->boolean('consent')->default(false); // the winner agreed to their photo being shown
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_media');
    }
};

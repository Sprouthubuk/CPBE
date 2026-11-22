<?php

use App\Support\GameIds;
use Illuminate\Database\Migrations\Migration;

/**
 * Database checks that competitions.skin and competitions.game hold allowed values.
 * competitions.skin already exists (string, default 'classic'): this changes no rows, only adds
 * database checks that skin and game hold allowed values (shared/game-ids.json), on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        GameIds::replaceDatabaseChecks();
    }

    public function down(): void
    {
        // The checks only refuse bad values; leaving them in place on a rollback is harmless.
    }
};

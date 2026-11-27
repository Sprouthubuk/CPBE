<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amendment 7: a registered entrant gets a unique reference per competition to write on their postal entry;
 * the postal entry provider submits references (CSV or its API) and entries are matched automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postal_references', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignUlid('competition_id')->constrained();
            $t->string('code', 16)->unique();
            $t->timestamps();
            $t->unique(['user_id', 'competition_id']);
        });
        Schema::table('postal_entries', function (Blueprint $t) {
            $t->foreignId('logged_by')->nullable()->change();          // null when the provider submitted it
            $t->string('source', 12)->default('admin')->after('status'); // admin | csv | provider
            $t->string('reference', 16)->nullable()->after('source');
            $t->string('provider_ref', 100)->nullable()->unique()->after('reference'); // the provider's own id: a resubmission enters once
        });
    }

    public function down(): void
    {
        Schema::table('postal_entries', fn (Blueprint $t) => $t->dropColumn(['source', 'reference', 'provider_ref']));
        Schema::dropIfExists('postal_references');
    }
};

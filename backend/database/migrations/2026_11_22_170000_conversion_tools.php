<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SOW 2.18: checkout and conversion tools: which tool brought each line, abandoned checkout recovery, A/B tests.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $t) {
            $t->string('via', 16)->nullable(); // upsell | repeat | recovery | recommendation
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->timestamp('recovery_sent_at')->nullable();
            $t->string('visitor_id', 40)->nullable(); // A/B tests: the consenting visitor's id
        });
        Schema::create('experiments', function (Blueprint $t) {
            $t->id();
            $t->string('key', 40)->unique();          // the placement on the site, e.g. home_hero_cta
            $t->string('name', 80);
            $t->json('variants');                     // [{ key, weight, text }]
            $t->boolean('active')->default(false);
            $t->timestamps();
        });
        Schema::create('experiment_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('experiment_id')->constrained()->cascadeOnDelete();
            $t->string('subject', 50);                // u:{user id} or v:{visitor id}
            $t->string('variant', 30);
            $t->timestamp('seen_at')->nullable();
            $t->timestamp('converted_at')->nullable();
            $t->unsignedInteger('revenue_pence')->default(0);
            $t->timestamps();
            $t->unique(['experiment_id', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiment_assignments');
        Schema::dropIfExists('experiments');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['recovery_sent_at', 'visitor_id']));
        Schema::table('order_lines', fn (Blueprint $t) => $t->dropColumn('via'));
    }
};

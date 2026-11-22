<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SOW 2.14: reporting and business intelligence: manual costs, alerts, settlement reconciliation,
// scheduled reports, and the marketing channel each order came from.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('channel', 40)->nullable()->index(); // utm_source / google / meta, from a consenting visitor's first visit
        });
        Schema::create('cost_entries', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('category', 20);                    // prizes | marketing | payment_fees | operating | other
            $t->string('basis', 10)->default('actual');    // actual | estimated | forecast
            $t->unsignedInteger('amount_pence');
            $t->string('channel', 40)->nullable();          // marketing: meta, google, …
            $t->foreignUlid('competition_id')->nullable()->constrained()->nullOnDelete();
            $t->string('note', 200)->nullable();
            $t->foreignId('entered_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['date', 'category']);
        });
        Schema::create('alert_rules', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 30);                        // see App\Models\AlertRule::KINDS
            $t->unsignedInteger('threshold');
            $t->boolean('active')->default(true);
            $t->timestamp('last_fired_at')->nullable();
            $t->timestamps();
        });
        Schema::create('settlement_lines', function (Blueprint $t) {
            $t->id();
            $t->string('batch', 40);                       // the upload it came in
            $t->string('reference', 80)->nullable();       // the provider's payment / job reference
            $t->string('order_number', 40)->nullable();
            $t->integer('amount_pence');
            $t->date('settled_on')->nullable();
            $t->string('order_id', 26)->nullable()->index();
            $t->string('match', 12);                       // matched | amount | unmatched
            $t->timestamps();
        });
        Schema::create('report_schedules', function (Blueprint $t) {
            $t->id();
            $t->string('email', 190);
            $t->string('frequency', 8);                    // daily | weekly | monthly
            $t->boolean('active')->default(true);
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('settlement_lines');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('cost_entries');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('channel'));
    }
};

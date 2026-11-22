<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SOW 2.15: chargebacks and disputes logged against orders, with an evidence pack; the IP an order came from.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('ip', 45)->nullable();
        });
        Schema::create('disputes', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('order_id')->constrained();
            $t->string('kind', 12)->default('chargeback');   // chargeback | dispute | retrieval
            $t->string('reference', 80)->nullable();         // the provider's case reference
            $t->unsignedInteger('amount_pence');
            $t->string('reason', 200);
            $t->string('status', 12)->default('open');       // open | evidence_sent | won | lost | accepted
            $t->date('respond_by')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('logged_by')->nullable()->constrained('users');
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('ip'));
    }
};

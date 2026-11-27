<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Amendment 11: the government's Voluntary Code of Good Practice for Prize Draw Operators. */
return new class extends Migration
{
    public function up(): void
    {
        // 1.3: whether this order's card payment may be a credit card (decided before the payment page opens).
        Schema::table('orders', fn (Blueprint $t) => $t->boolean('credit_allowed')->default(false)->after('card_type'));
        // 1.4: players are asked to set a spend limit at sign-up or before their second entry.
        Schema::table('users', fn (Blueprint $t) => $t->timestamp('limits_prompted_at')->nullable());
        // 1.6 / 1.7: signs of harm for a person to review and act on.
        Schema::create('harm_flags', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('indicator', 40);
            $t->string('detail', 300);
            $t->string('key', 120)->unique(); // one flag per indicator per period
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->string('outcome', 300)->nullable();
            $t->timestamps();
            $t->index(['reviewed_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harm_flags');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('limits_prompted_at'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('credit_allowed'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SOW 2.3: discount codes as a fixed amount or a percentage, with use limits, start and expiry dates and a minimum spend.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $t) {
            $t->string('discount_type', 10)->default('percent')->after('code'); // percent | fixed
            $t->unsignedTinyInteger('percent_off')->nullable()->change();
            $t->unsignedInteger('amount_off_pence')->nullable()->after('percent_off');
            $t->unsignedInteger('min_spend_pence')->default(0)->after('amount_off_pence');
            $t->unsignedInteger('max_uses_per_player')->nullable()->after('max_uses');
            $t->boolean('include_bundles')->default(false)->after('max_uses_per_player');
            $t->timestamp('starts_at')->nullable()->after('include_bundles');
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $t) {
            $t->dropColumn(['discount_type', 'amount_off_pence', 'min_spend_pence', 'max_uses_per_player', 'include_bundles', 'starts_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SOW 2.16: separate admin and staff roles. Everyone who can open the admin keeps is_admin; admin_role says what they see.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('admin_role', 20)->nullable()->after('is_staff');
        });
        DB::table('users')->where('is_admin', true)->update(['admin_role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('admin_role'));
    }
};

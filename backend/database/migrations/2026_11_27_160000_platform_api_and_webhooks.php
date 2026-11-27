<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Amendment 2: API and webhook access to the Platform and the Client's data, for any tool the Client chooses. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->string('token_hash', 64)->unique(); // SHA-256 of the token: the token itself is shown once and never kept
            $t->string('hint', 12);                 // the last characters, to tell tokens apart
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('webhook_endpoints', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->string('url', 500);
            $t->string('secret', 64);
            $t->json('events');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $t->string('event', 40);
            $t->json('payload');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->unsignedSmallInteger('last_status')->nullable();
            $t->timestamps();
            $t->index(['delivered_at', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_tokens');
    }
};

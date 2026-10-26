<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform onboarding (POST /platform/restaurants) replays a retried
     * request by its Idempotency-Key instead of creating a second
     * restaurant. Both columns stay null for every existing restaurant and
     * for restaurants created through the tenant endpoint.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('onboarding_idempotency_key', 100)->nullable()->unique();
            $table->string('onboarding_payload_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropUnique(['onboarding_idempotency_key']);
            $table->dropColumn(['onboarding_idempotency_key', 'onboarding_payload_hash']);
        });
    }
};

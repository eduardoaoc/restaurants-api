<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grants a User a platform role — completely independent of
     * organization_id/restaurant_id (unlike user_roles). A platform admin
     * is a User row with a row here; nothing about being a platform admin
     * depends on tenant membership, and nothing about tenant membership
     * grants platform access. A user may hold more than one platform role
     * (e.g. support + billing_admin in the future), hence the unique pair
     * rather than a single platform_role_id column on users.
     */
    public function up(): void
    {
        Schema::create('platform_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'platform_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_role_assignments');
    }
};

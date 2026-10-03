<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-level roles (super_admin, and future support/billing_admin/
     * operations_admin/read_only_support) — deliberately a table of its
     * own, never a row in `roles`. `roles` is organization/restaurant
     * scoped by construction (see user_roles.organization_id); a platform
     * role must never be assignable within a tenant context, so it cannot
     * live in the same catalog. See the Bloco 0 report.
     */
    public function up(): void
    {
        Schema::create('platform_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_roles');
    }
};

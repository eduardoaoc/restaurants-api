<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-level permissions, mirroring `permissions` structurally but
     * kept in a fully separate table so a platform ability can never be
     * granted through a tenant `role_permissions` row by mistake.
     */
    public function up(): void
    {
        Schema::create('platform_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_permissions');
    }
};

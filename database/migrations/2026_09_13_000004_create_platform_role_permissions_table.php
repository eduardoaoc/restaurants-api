<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['platform_role_id', 'platform_permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_role_permissions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            // CARTA 8.2A: whether staff holding manage_tables WITHOUT
            // manage_floor_plan (i.e. waiters) may change the STRUCTURE of
            // the restaurant's tables (create, name/number/capacity). NOT
            // NULL DEFAULT true backfills every existing restaurant with
            // the pre-8.2A behavior — never a null to interpret later. See
            // TablePolicy::canManageStructure().
            $table->boolean('waiter_table_management_enabled')->default(true)->after('google_review_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->dropColumn('waiter_table_management_enabled');
        });
    }
};

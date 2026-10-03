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
        // RestaurantDashboardService's queries each filter by (restaurant_id,
        // <period timestamp>) — none of these composites existed yet. The
        // staff-performance indexes (Bloco 15) are keyed by *_by_user_id
        // instead of restaurant_id, so they don't help here.
        Schema::table('payment_records', function (Blueprint $table) {
            $table->index(['restaurant_id', 'recorded_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['restaurant_id', 'created_at']);
            $table->index(['restaurant_id', 'served_at']);
        });

        Schema::table('table_sessions', function (Blueprint $table) {
            $table->index(['restaurant_id', 'opened_at']);
            $table->index(['restaurant_id', 'closed_at']);
        });

        Schema::table('table_requests', function (Blueprint $table) {
            $table->index(['restaurant_id', 'created_at']);
            $table->index(['restaurant_id', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_records', function (Blueprint $table) {
            $table->dropIndex(['restaurant_id', 'recorded_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['restaurant_id', 'created_at']);
            $table->dropIndex(['restaurant_id', 'served_at']);
        });

        Schema::table('table_sessions', function (Blueprint $table) {
            $table->dropIndex(['restaurant_id', 'opened_at']);
            $table->dropIndex(['restaurant_id', 'closed_at']);
        });

        Schema::table('table_requests', function (Blueprint $table) {
            $table->dropIndex(['restaurant_id', 'created_at']);
            $table->dropIndex(['restaurant_id', 'completed_at']);
        });
    }
};

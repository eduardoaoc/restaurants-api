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
        // Every staff performance metric is a COUNT/COUNT(DISTINCT) filtered
        // by exactly one actor column plus its matching timestamp — these
        // composite indexes are what StaffPerformanceService's queries hit.
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['created_by_user_id', 'created_at']);
            $table->index(['served_by_user_id', 'served_at']);
            $table->index(['approved_by_user_id', 'approved_at']);
        });

        Schema::table('table_requests', function (Blueprint $table) {
            $table->index(['completed_by_user_id', 'completed_at']);
        });

        Schema::table('table_sessions', function (Blueprint $table) {
            $table->index(['closed_by_user_id', 'closed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['created_by_user_id', 'created_at']);
            $table->dropIndex(['served_by_user_id', 'served_at']);
            $table->dropIndex(['approved_by_user_id', 'approved_at']);
        });

        Schema::table('table_requests', function (Blueprint $table) {
            $table->dropIndex(['completed_by_user_id', 'completed_at']);
        });

        Schema::table('table_sessions', function (Blueprint $table) {
            $table->dropIndex(['closed_by_user_id', 'closed_at']);
        });
    }
};

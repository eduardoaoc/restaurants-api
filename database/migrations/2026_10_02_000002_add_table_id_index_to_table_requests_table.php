<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GET /table-requests?table_id= (CARTA 8.2A) filters on table_id and
     * orders by created_at. table_requests is append-only operational
     * history and PostgreSQL does not index foreign keys on its own, so
     * without this the per-table filter scans every request of the
     * restaurant (via restaurant_id_created_at) and discards the other
     * tables' rows. Also backs the table_id restrictOnDelete FK check.
     */
    public function up(): void
    {
        Schema::table('table_requests', function (Blueprint $table) {
            $table->index(['table_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('table_requests', function (Blueprint $table) {
            $table->dropIndex(['table_id', 'created_at']);
        });
    }
};

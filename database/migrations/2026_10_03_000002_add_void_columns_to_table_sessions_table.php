<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A voided session (CARTA 9.1A — VoidEmptyTableSessionAction) keeps
     * status = 'closed', so isActive(), Table::activeSession() and the
     * table_sessions_one_active_per_table partial unique index (status <>
     * 'closed') keep working untouched and the table is freed. voided_at
     * is what tells it apart from a real, served-and-paid close: every
     * query that counts sessions as attended (closed sessions, guests,
     * turnover, staff "closed" metrics) excludes voided_at IS NOT NULL.
     */
    public function up(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('closed_at');
            $table->foreignId('voided_by_user_id')->nullable()->after('closed_by_user_id')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable()->after('voided_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by_user_id');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The waiter responsible for a table session — Bloco 2. Belongs to the
     * SESSION, not the Table: the physical table outlives every session,
     * while the responsible waiter changes per service. Nullable (a session
     * can be unassigned) and nullOnDelete: suspending/removing a User must
     * never destroy a historical TableSession row — see the Bloco 2 report.
     */
    public function up(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->foreignId('assigned_waiter_user_id')
                ->nullable()
                ->after('closed_by_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_waiter_user_id');
        });
    }
};

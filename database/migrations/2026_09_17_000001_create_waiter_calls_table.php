<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Bloco 4 — "call responsible waiter": an internal Manager/Owner ->
     * assigned-waiter escalation on an active TableSession. Deliberately
     * its own minimal resource, NOT a TableRequest row: TableRequest is
     * strictly customer-originated (call_waiter/request_bill via the
     * public QR flow), and its partial unique index is scoped per
     * (table_session_id, type) — reusing TYPE_CALL_WAITER here would
     * collide with a customer's own pending call and conflate two
     * different actors/semantics. See the Bloco 4 report.
     */
    public function up(): void
    {
        Schema::create('waiter_calls', function (Blueprint $table) {
            $table->id();

            // Operational history: must never cascade-delete a call out of
            // existence — same reasoning as table_requests.
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('table_session_id')->constrained('table_sessions')->restrictOnDelete();
            $table->foreignId('waiter_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('called_by_user_id')->constrained('users')->restrictOnDelete();

            $table->string('status')->default('pending')->index();

            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamps();

            $table->index(['table_session_id', 'status']);
        });

        // At most one open (pending) call per table session — a simple
        // spam guard, not a per-waiter constraint: only one ping needs to
        // be outstanding for a session at a time, regardless of whether
        // the assigned waiter changes in between.
        DB::statement(
            "CREATE UNIQUE INDEX waiter_calls_one_pending_per_session ON waiter_calls (table_session_id) WHERE status = 'pending'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waiter_calls');
    }
};

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
     * Bloco 3 — canonical source of operational presence: "is this staff
     * member active at this Restaurant right now?" Deliberately NOT a
     * timesheet/payroll table — see the Bloco 3 report. No `status` column:
     * active is derived as `ended_at IS NULL`, a single source of truth
     * (mirrors TableSession.isActive()).
     *
     * Scoped by restaurant_id, not organization_id: the same User can be
     * independently active at Restaurant A and Restaurant B at once (Bloco
     * 18 multi-restaurant staff) — see StaffShiftEligibility.
     */
    public function up(): void
    {
        Schema::create('staff_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users');
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['restaurant_id', 'started_at']);
        });

        // At most one ACTIVE shift per (user, restaurant) — the real
        // safety net against two concurrent "start" requests, exactly like
        // table_sessions_one_active_per_table. Historical (ended) rows are
        // unrestricted: the same user can have many past shifts at the
        // same restaurant.
        DB::statement(
            'CREATE UNIQUE INDEX staff_shifts_one_active_per_user_restaurant ON staff_shifts (user_id, restaurant_id) WHERE ended_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_shifts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CARTA 9.1A — product decision: this version has NO forced close (any
     * active table session blocks every close, for every user). Removes
     * the forced/force_reason columns added by 2026_10_03_000006, which
     * stays in history as already applied; its preparation-threshold part
     * (25 -> 30) remains valid.
     *
     * Also removes the force_daily_close permission and its role
     * assignments from environments where it was seeded, so no orphan
     * capability stays active.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE restaurant_day_closes DROP CONSTRAINT IF EXISTS restaurant_day_closes_force_reason_check');

        if (Schema::hasColumn('restaurant_day_closes', 'forced')) {
            Schema::table('restaurant_day_closes', function (Blueprint $table) {
                $table->dropColumn(['forced', 'force_reason']);
            });
        }

        $permissionIds = DB::table('permissions')->where('slug', 'force_daily_close')->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }

    /**
     * Restores the columns only (the permission is never re-created here —
     * seeders own permission definitions).
     */
    public function down(): void
    {
        Schema::table('restaurant_day_closes', function (Blueprint $table) {
            $table->boolean('forced')->default(false)->after('has_incidents');
            $table->string('force_reason', 500)->nullable()->after('forced');
        });

        DB::statement('ALTER TABLE restaurant_day_closes ADD CONSTRAINT restaurant_day_closes_force_reason_check CHECK (NOT forced OR force_reason IS NOT NULL)');
    }
};

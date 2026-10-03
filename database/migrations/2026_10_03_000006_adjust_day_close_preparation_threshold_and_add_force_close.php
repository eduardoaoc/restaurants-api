<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CARTA 9.1A final adjustment:
     *  - preparation delay default is 30 minutes (final product decision,
     *    was 25). Rows still holding the previous default are moved to 30
     *    — the setting was introduced in this same release, so 25 can only
     *    be the old default, never an explicit choice;
     *  - a Cierre Diario can be FORCED past its blockers (active sessions)
     *    by holders of force_daily_close, with a mandatory reason.
     */
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('preparation_delay_threshold_minutes')->default(30)->change();
        });

        DB::table('restaurant_settings')->where('preparation_delay_threshold_minutes', 25)->update(['preparation_delay_threshold_minutes' => 30]);

        Schema::table('restaurant_day_closes', function (Blueprint $table) {
            $table->boolean('forced')->default(false)->after('has_incidents');
            $table->string('force_reason', 500)->nullable()->after('forced');
        });

        DB::statement('ALTER TABLE restaurant_day_closes ADD CONSTRAINT restaurant_day_closes_force_reason_check CHECK (NOT forced OR force_reason IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE restaurant_day_closes DROP CONSTRAINT IF EXISTS restaurant_day_closes_force_reason_check');

        Schema::table('restaurant_day_closes', function (Blueprint $table) {
            $table->dropColumn(['forced', 'force_reason']);
        });

        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('preparation_delay_threshold_minutes')->default(25)->change();
        });
    }
};

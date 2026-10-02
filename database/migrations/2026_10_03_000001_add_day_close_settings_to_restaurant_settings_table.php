<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cierre Diario configuration (CARTA 9.1A). Every column is NOT NULL
     * with a DB default (except default_opening_float, where null has a
     * real meaning: "no default float — the first close must state it"),
     * so existing restaurants are backfilled with the documented defaults.
     */
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            // Local wall-clock "HH:MM" (restaurant timezone). A close whose
            // local time is before it belongs to the previous business
            // date — see DayClosePeriodResolver.
            $table->string('business_day_cutoff_time', 5)->default('06:00')->after('waiter_table_management_enabled');
            $table->decimal('default_opening_float', 10, 2)->nullable()->after('business_day_cutoff_time');
            $table->decimal('cash_difference_note_threshold', 10, 2)->default('5.00')->after('default_opening_float');
            $table->unsignedSmallInteger('accept_delay_threshold_minutes')->default(10)->after('cash_difference_note_threshold');
            $table->unsignedSmallInteger('preparation_delay_threshold_minutes')->default(25)->after('accept_delay_threshold_minutes');
            $table->unsignedSmallInteger('ready_pickup_delay_threshold_minutes')->default(10)->after('preparation_delay_threshold_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->dropColumn([
                'business_day_cutoff_time', 'default_opening_float', 'cash_difference_note_threshold',
                'accept_delay_threshold_minutes', 'preparation_delay_threshold_minutes', 'ready_pickup_delay_threshold_minutes',
            ]);
        });
    }
};

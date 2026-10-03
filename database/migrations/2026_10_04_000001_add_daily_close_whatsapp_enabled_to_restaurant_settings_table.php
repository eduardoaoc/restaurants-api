<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CARTA 9.1E: automatic WhatsApp delivery of the Cierre Diario, per
     * restaurant. Default false — no existing restaurant is enabled by the
     * migration; enabling requires an active recipient with consent (see
     * DayCloseWhatsAppSettingsController).
     */
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->boolean('daily_close_whatsapp_enabled')->default(false)->after('ready_pickup_delay_threshold_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->dropColumn('daily_close_whatsapp_enabled');
        });
    }
};

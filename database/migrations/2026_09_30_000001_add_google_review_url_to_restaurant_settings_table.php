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
        Schema::table('restaurant_settings', function (Blueprint $table) {
            // Owner-supplied direct link to the restaurant's Google Business
            // Profile review form (CARTA 5.3A). null = Google Review off —
            // there is deliberately no separate *_enabled flag. Only
            // GoogleReviewUrl-valid HTTPS Google links are ever stored.
            // 2048 = the de-facto URL length ceiling (also Google Maps
            // URLs' own documented limit).
            $table->string('google_review_url', 2048)->nullable()->after('bill_receipt_printing_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->dropColumn('google_review_url');
        });
    }
};

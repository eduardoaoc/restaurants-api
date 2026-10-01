<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kitchen Dashboard (CARTA 7.1A) — "today" windows anchored on
     * accepted_at / ready_at (recent lists, timings), polled frequently.
     * Without these, Postgres can only narrow by restaurant_id and then
     * filters that restaurant's ENTIRE order history on every request
     * (measured on a 600k-row simulation: ~66 ms / ~92 ms reading 60k
     * rows, vs ~0.15 ms / ~0.7 ms with these). (restaurant_id, served_at)
     * already exists (2026_09_11 reporting indexes). Also serves
     * KitchenAnalytics' existing ready_at period filter.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['restaurant_id', 'accepted_at']);
            $table->index(['restaurant_id', 'ready_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['restaurant_id', 'accepted_at']);
            $table->dropIndex(['restaurant_id', 'ready_at']);
        });
    }
};

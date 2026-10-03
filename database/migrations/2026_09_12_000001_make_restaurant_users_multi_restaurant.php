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
        // Bloco 18: an operational staff member may now belong to 1..N
        // restaurants of the same organization (Carlos -> A + B), instead
        // of exactly one. The single-restaurant-per-user constraint is
        // replaced with a per-(user, restaurant) one — a user still cannot
        // be linked twice to the *same* restaurant, but can be linked to
        // several different ones.
        Schema::table('restaurant_users', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->unique(['user_id', 'restaurant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_users', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'restaurant_id']);
            $table->unique('user_id');
        });
    }
};

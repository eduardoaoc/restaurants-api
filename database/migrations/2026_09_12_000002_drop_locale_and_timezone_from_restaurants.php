<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `restaurants.timezone`/`default_locale` predate this block and were
     * never read by any actual business logic — only round-tripped through
     * Restaurant CRUD. Bloco 18 introduces `restaurant_settings` as the one
     * authoritative place for operational configuration (locale, currency,
     * timezone, feature toggles); keeping these two columns here as well
     * would leave two disagreeing sources of truth for the same concept.
     * Dropped rather than kept dormant — see report.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'default_locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('timezone')->default('UTC');
            $table->string('default_locale')->default('pt-BR');
        });
    }
};

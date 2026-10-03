<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('restaurant_settings', function (Blueprint $table) {
            $table->id();

            // Denormalized alongside restaurant_id (rather than derived via
            // a join every time) so every query that already filters
            // operational data by organization_id can do the same here
            // without an extra hop through restaurants.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->unique()->constrained()->restrictOnDelete();

            $table->string('default_locale');
            $table->jsonb('enabled_locales');

            $table->string('currency', 3);
            $table->string('timezone');

            $table->boolean('customer_ordering_enabled')->default(true);
            $table->boolean('customer_order_requires_approval')->default(false);

            $table->boolean('waiter_call_enabled')->default(true);
            $table->boolean('bill_request_enabled')->default(true);

            $table->boolean('kitchen_ticket_printing_enabled')->default(true);
            $table->boolean('bill_receipt_printing_enabled')->default(true);

            $table->timestamps();
        });

        // Backfill: every restaurant that already existed before this
        // migration must end up with a settings row too — nothing should
        // ever observe $restaurant->settings === null. Defaults match the
        // initial market (Valencia/Spain) — see RestaurantSettings.
        $now = now();
        $restaurants = DB::table('restaurants')->select('id', 'organization_id')->get();

        $enabledLocales = json_encode(['es-ES', 'ca-ES-valencia', 'en-GB']);

        foreach ($restaurants as $restaurant) {
            DB::table('restaurant_settings')->insert([
                'organization_id' => $restaurant->organization_id,
                'restaurant_id' => $restaurant->id,
                'default_locale' => 'es-ES',
                'enabled_locales' => $enabledLocales,
                'currency' => 'EUR',
                'timezone' => 'Europe/Madrid',
                'customer_ordering_enabled' => true,
                'customer_order_requires_approval' => false,
                'waiter_call_enabled' => true,
                'bill_request_enabled' => true,
                'kitchen_ticket_printing_enabled' => true,
                'bill_receipt_printing_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_settings');
    }
};

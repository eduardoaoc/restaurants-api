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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Operational reference (pricing/availability/modifiers came from
            // here at order time) and optional catalog reference. Neither is
            // the source of truth for what was actually ordered — the
            // snapshot columns below are.
            $table->foreignId('restaurant_product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name_snapshot');
            $table->text('product_description_snapshot')->nullable();
            $table->decimal('unit_price_snapshot', 10, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('modifiers_unit_total_snapshot', 10, 2);
            $table->decimal('unit_total_snapshot', 10, 2);
            $table->decimal('line_total_snapshot', 10, 2);
            $table->text('customer_note')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('restaurant_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};

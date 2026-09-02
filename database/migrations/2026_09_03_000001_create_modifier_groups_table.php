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
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            // Modifiers are scoped to a RestaurantProduct (not directly to Product),
            // since the same Product can have different modifiers per Restaurant.
            $table->foreignId('restaurant_product_id')->constrained()->cascadeOnDelete();
            $table->string('internal_name');
            $table->unsignedInteger('min_select')->default(0);
            $table->unsignedInteger('max_select');
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modifier_groups');
    }
};

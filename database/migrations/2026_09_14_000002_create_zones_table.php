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
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            // Denormalized alongside floor_id (same convention as Table's own
            // restaurant_id): every restaurant-scoped query filters on
            // restaurant_id directly, without joining through floors.
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete, not cascade: a Floor with zones must be
            // emptied explicitly first (see FloorController::destroy) —
            // deleting a Floor must never silently wipe out its Zones.
            $table->foreignId('floor_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};

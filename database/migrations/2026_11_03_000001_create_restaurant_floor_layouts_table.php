<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One saved 3D floor layout per restaurant, stored as a versioned
     * document. Layout CONFIGURATION only (floor size, tables' number/shape/
     * seats/position/rotation) — never operational state. The unique
     * restaurant_id makes "replace the whole document" an upsert.
     */
    public function up(): void
    {
        Schema::create('restaurant_floor_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->unique()->constrained()->cascadeOnDelete();
            // Version of the document SCHEMA (the contract), not a revision counter.
            $table->unsignedSmallInteger('schema_version');
            $table->jsonb('layout');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_floor_layouts');
    }
};

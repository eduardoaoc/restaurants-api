<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * All layout columns are nullable/defaulted so existing tables keep
     * working unassigned to any zone, with no backfill required (see the
     * Bloco 1 report, "Default data"). zone_id restricts on delete —
     * deleting a Zone with tables still assigned must fail explicitly (see
     * ZoneController::destroy), never silently null them out.
     */
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable()->after('restaurant_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('capacity')->nullable()->after('number');

            // Normalized canvas coordinates (0..1 on each axis) — resolution/
            // viewport-independent, the frontend scales to whatever it
            // renders at. Null until the editor places the table.
            $table->decimal('layout_x', 6, 5)->nullable();
            $table->decimal('layout_y', 6, 5)->nullable();

            $table->unsignedSmallInteger('layout_rotation')->default(0);
            $table->string('layout_shape')->default('square');

            // Canvas-unit visual dimensions, unrelated to the table's real
            // physical size — purely how big it renders on the map.
            $table->decimal('layout_width', 6, 2)->default(80);
            $table->decimal('layout_height', 6, 2)->default(80);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
            $table->dropColumn([
                'capacity', 'layout_x', 'layout_y', 'layout_rotation', 'layout_shape', 'layout_width', 'layout_height',
            ]);
        });
    }
};

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
        Schema::table('products', function (Blueprint $table) {
            // Null means "allergen declaration not made yet" (legacy data);
            // [] means "declared explicitly: none of the 14 EU groups apply".
            // Never default this to [] — that would erase the distinction.
            $table->jsonb('allergens')->nullable()->after('status');

            $table->unsignedInteger('calories_kcal')->nullable()->after('allergens');
            $table->decimal('protein_g', 6, 2)->nullable()->after('calories_kcal');
            $table->decimal('carbohydrates_g', 6, 2)->nullable()->after('protein_g');
            $table->decimal('fat_g', 6, 2)->nullable()->after('carbohydrates_g');
            $table->decimal('salt_g', 6, 2)->nullable()->after('fat_g');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['allergens', 'calories_kcal', 'protein_g', 'carbohydrates_g', 'fat_g', 'salt_g']);
        });
    }
};

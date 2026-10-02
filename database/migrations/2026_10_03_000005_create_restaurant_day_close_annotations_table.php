<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Post-close notes on a Cierre Diario (CARTA 9.1A) — the only way to
     * add information to a close after the fact. Append-only (created_at
     * only); never touches the close's report/hash/totals.
     */
    public function up(): void
    {
        Schema::create('restaurant_day_close_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_day_close_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('body', 2000);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name_snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['restaurant_day_close_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_day_close_annotations');
    }
};

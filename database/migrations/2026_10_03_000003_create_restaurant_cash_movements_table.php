<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual cash drawer movements (CARTA 9.1A): pay-ins and pay-outs
     * ("entradas"/"retiradas") that change how much cash should be in the
     * drawer at the Cierre Diario. Append-only — a mistake is corrected
     * with an opposite movement, never an UPDATE/DELETE. Attributed to a
     * close by recorded_at in [period_started_at, period_ended_at), like
     * payment_records.
     */
    public function up(): void
    {
        Schema::create('restaurant_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->decimal('amount', 10, 2);
            $table->string('reason', 255);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name_snapshot');
            $table->timestamp('recorded_at');
            $table->string('idempotency_key', 100);
            $table->string('payload_hash', 64);
            $table->timestamps();

            $table->unique(['restaurant_id', 'idempotency_key']);
            $table->index(['restaurant_id', 'recorded_at']);
        });

        DB::statement("ALTER TABLE restaurant_cash_movements ADD CONSTRAINT restaurant_cash_movements_type_check CHECK (type IN ('pay_in', 'pay_out'))");
        DB::statement('ALTER TABLE restaurant_cash_movements ADD CONSTRAINT restaurant_cash_movements_amount_positive CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_cash_movements');
    }
};

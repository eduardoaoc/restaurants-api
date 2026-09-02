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
        Schema::create('payment_records', function (Blueprint $table) {
            $table->id();

            // Financial history: restaurant/table/session links must never
            // cascade-delete a payment out of existence.
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('table_id')->constrained('tables')->restrictOnDelete();
            $table->foreignId('table_session_id')->constrained('table_sessions')->restrictOnDelete();

            $table->string('method');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EUR');

            $table->string('reference', 100)->nullable();
            $table->text('note')->nullable();

            // Double-submit protection for the payment endpoint (see
            // RecordPaymentAction), same pattern as orders.idempotency_key.
            // Null keys never collide: Postgres treats every NULL as
            // distinct under a plain unique constraint.
            $table->string('idempotency_key', 100)->nullable();
            $table->string('payload_hash', 64)->nullable();

            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');

            $table->timestamps();

            $table->index('restaurant_id');
            $table->index('table_session_id');
            $table->index('created_at');
            $table->unique(['table_session_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_records');
    }
};

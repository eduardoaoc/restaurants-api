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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Orders are historical records: restaurant/table/session/user
            // links must never cascade-delete an order out of existence.
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('table_id')->constrained('tables')->restrictOnDelete();
            $table->foreignId('table_session_id')->constrained('table_sessions')->restrictOnDelete();

            $table->string('origin');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('customer_name', 100)->nullable();
            $table->string('status')->default('waiting_approval')->index();

            $table->decimal('subtotal', 10, 2);
            $table->decimal('modifiers_total', 10, 2);
            $table->decimal('total', 10, 2);

            $table->text('customer_note')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Double-submit protection for the public create-order endpoint
            // (see CreatePublicOrderAction). Null keys never collide: Postgres
            // treats every NULL as distinct under a plain unique constraint.
            $table->string('idempotency_key', 100)->nullable();
            $table->string('idempotency_payload_hash', 64)->nullable();

            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
            $table->index('table_id');
            $table->index('created_at');
            $table->unique(['table_session_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

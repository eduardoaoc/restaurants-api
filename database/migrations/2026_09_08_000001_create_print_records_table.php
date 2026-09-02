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
        Schema::create('print_records', function (Blueprint $table) {
            $table->id();

            // Operational audit trail: organization/restaurant/order/session
            // links must never cascade-delete a print record out of
            // existence.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();

            $table->string('document_type');

            // kitchen_ticket: order_id set (table_session_id also set, from
            // the order's own session, for convenience). bill_receipt:
            // table_session_id set, order_id null.
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('table_session_id')->nullable()->constrained('table_sessions')->restrictOnDelete();

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Documents nothing about the physical printer — only that this
            // document was requested/generated. Never rename to something
            // implying delivery confirmation (e.g. printed_successfully).
            $table->timestamp('generated_at');

            $table->timestamps();

            $table->index(['restaurant_id', 'created_at']);
            $table->index('order_id');
            $table->index('table_session_id');
            $table->index('document_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('print_records');
    }
};

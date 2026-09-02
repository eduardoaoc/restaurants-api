<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('table_requests', function (Blueprint $table) {
            $table->id();

            // Operational history: restaurant/table/session links must
            // never cascade-delete a request out of existence.
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('table_id')->constrained('tables')->restrictOnDelete();
            $table->foreignId('table_session_id')->constrained('table_sessions')->restrictOnDelete();

            $table->string('type');
            $table->string('status')->default('pending')->index();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
            $table->index('table_session_id');
            $table->index('type');
            $table->index('created_at');
        });

        // At most one open (pending/acknowledged) request per type per
        // table session — the real guard against double-submit/spam, not
        // just an application-level check. Once a request is
        // completed/cancelled it falls outside this index and a new one
        // of the same type can be opened.
        DB::statement(
            "CREATE UNIQUE INDEX table_requests_one_open_per_session_type ON table_requests (table_session_id, type) WHERE status IN ('pending', 'acknowledged')"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_requests');
    }
};

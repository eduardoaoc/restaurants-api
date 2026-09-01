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
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('tables')->cascadeOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users');
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users');
            $table->unsignedInteger('guest_count');
            $table->string('status')->default('occupied')->index();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        // A table can have many historical sessions, but only one that is not
        // "closed" at a time. This is enforced at the database level (rather
        // than only in application code) so a race between two concurrent
        // "open" requests cannot create two active sessions for the same table.
        DB::statement(
            'CREATE UNIQUE INDEX table_sessions_one_active_per_table ON table_sessions (table_id) WHERE status <> \'closed\''
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_sessions');
    }
};

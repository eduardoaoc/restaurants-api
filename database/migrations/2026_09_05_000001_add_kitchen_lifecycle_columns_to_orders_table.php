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
        Schema::table('orders', function (Blueprint $table) {
            // Kitchen/lifecycle audit trail. Every *_by_user_id is nullable
            // and nullOnDelete(): if the user is later removed, the order
            // (and the fact that this step happened) must remain.
            $table->foreignId('accepted_by_user_id')->nullable()->after('cancelled_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable()->after('cancelled_at');

            $table->foreignId('preparing_by_user_id')->nullable()->after('accepted_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('preparing_at')->nullable()->after('accepted_at');

            $table->foreignId('ready_by_user_id')->nullable()->after('preparing_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('ready_at')->nullable()->after('preparing_at');

            $table->foreignId('served_by_user_id')->nullable()->after('ready_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('served_at')->nullable()->after('ready_at');
        });

        // The KDS queue always filters by (restaurant_id, status) and orders
        // by created_at; extend the existing composite index with it rather
        // than adding a second, overlapping one — the 2-column index was a
        // strict prefix of this one, so it's redundant once this exists.
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_restaurant_id_status_index');
            $table->index(['restaurant_id', 'status', 'created_at'], 'orders_restaurant_id_status_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_restaurant_id_status_created_at_index');
            $table->index(['restaurant_id', 'status']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_by_user_id');
            $table->dropConstrainedForeignId('preparing_by_user_id');
            $table->dropConstrainedForeignId('ready_by_user_id');
            $table->dropConstrainedForeignId('served_by_user_id');
            $table->dropColumn(['accepted_at', 'preparing_at', 'ready_at', 'served_at']);
        });
    }
};

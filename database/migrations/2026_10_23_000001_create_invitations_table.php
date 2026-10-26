<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One table for both invitation kinds (owner by a platform admin, staff
     * by an owner/manager): same secret, expiry, delivery and acceptance
     * rules — see App\Models\Invitation. The secret itself is never stored,
     * only its SHA-256 (token_hash); it is minted when the e-mail is built.
     *
     * The partial unique index is the database-level guarantee that a
     * double click / retry can never leave two usable invitations for the
     * same person in the same organization.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('email');
            $table->string('name')->nullable();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->jsonb('restaurant_assignments')->nullable();
            $table->string('locale');
            $table->string('token_hash', 64)->unique();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->string('delivery_status')->default('queued');
            $table->unsignedInteger('delivery_sequence')->default(0);
            $table->unsignedInteger('send_count')->default(0);
            $table->timestamp('last_queued_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('delivery_failed_at')->nullable();
            $table->string('invited_by_type');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'type', 'status']);
            $table->index('email');
        });

        DB::statement("CREATE UNIQUE INDEX invitations_one_pending_per_email ON invitations (organization_id, email) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};

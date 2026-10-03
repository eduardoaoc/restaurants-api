<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->nullable()->constrained()->restrictOnDelete();

            // nullOnDelete: the audit trail survives even if the acting
            // user is later removed — actor_type still says who/what kind
            // of actor performed the event.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type');

            $table->string('event');

            // No FK: resource_type/resource_id are a historical logical
            // reference, not a live relation — the audit log must survive
            // even if the referenced row (or its whole table) is gone.
            $table->string('resource_type');
            $table->unsignedBigInteger('resource_id')->nullable();

            $table->jsonb('changes')->nullable();
            $table->jsonb('metadata')->nullable();

            // Append-only, immutable: created_at only, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['restaurant_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operational activity feed (CARTA 6.1A) — "what happened" in a
        // restaurant's service, one row per real domain transition,
        // written by RestaurantActivityRecorder inside the same
        // transaction as the mutation. Deliberately NOT audit_logs (a
        // security/administrative trail with its own contract) and NOT
        // the Operations Live alerts (current state, never history).
        //
        // Append-only: rows are never updated nor deleted by the
        // application. Retention/purging is an explicit future decision —
        // nothing here purges anything.
        Schema::create('restaurant_activity_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();

            $table->string('type', 64);
            $table->string('category', 32);
            $table->timestamp('occurred_at');

            // staff | customer. actor_user_id is only ever set
            // for staff; nullOnDelete like audit_logs — the name snapshot
            // keeps the row readable if the user row is ever removed.
            $table->string('actor_type', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name_snapshot')->nullable();

            // No FK on these: historical logical references, rendered
            // from the snapshots next to them (same reasoning as
            // audit_logs.resource_id) — the feed must never need a join
            // to render, and must survive any future cleanup of the
            // referenced rows.
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('table_name_snapshot')->nullable();
            $table->unsignedBigInteger('table_session_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_reference', 32)->nullable();
            $table->unsignedBigInteger('table_request_id')->nullable();

            // Per-type, whitelisted keys only — see RestaurantActivityType::METADATA_KEYS.
            $table->jsonb('metadata')->nullable();

            // Immutable: created_at only, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            // Feed (newest first), cursor pagination and unread counts
            // are all "WHERE restaurant_id = ? ORDER BY/COMPARE id".
            $table->index(['restaurant_id', 'id']);
            // ?from=&to= period filter.
            $table->index(['restaurant_id', 'occurred_at']);
        });

        // One read cursor per (restaurant, user) — never one row per
        // event per user. Everything in the restaurant's feed with
        // id > last_read_event_id is unread for that user. user_id (not
        // restaurant_users) because an owner reaches every restaurant of
        // the organization without any restaurant_users row (see
        // RestaurantScope).
        Schema::create('restaurant_activity_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // No FK: a future retention purge of old events must never be
            // blocked by (or cascade into) someone's read cursor.
            $table->unsignedBigInteger('last_read_event_id');
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['restaurant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_activity_reads');
        Schema::dropIfExists('restaurant_activity_events');
    }
};

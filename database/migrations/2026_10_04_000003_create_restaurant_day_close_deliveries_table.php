<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each attempt to deliver a Cierre Diario by WhatsApp (CARTA 9.1E):
     * the automatic one created with the close, and every manual resend.
     * The recipient is SNAPSHOTTED (name, encrypted phone, mask, hash) so a
     * later recipient change never rewrites history. Never part of the
     * close's immutable snapshot — a delivery failing never touches it.
     *
     * Status: pending -> accepted (Meta took the request: NOT delivered)
     * -> sent -> delivered -> read (webhooks), or failed / skipped.
     * sending_started_at marks a claimed, in-flight provider call: a job
     * that finds a pending delivery with it set knows the outcome of that
     * call is unknown and never re-sends blindly.
     */
    public function up(): void
    {
        Schema::create('restaurant_day_close_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_day_close_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('channel', 16)->default('whatsapp');
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->foreignId('recipient_id')->nullable()->constrained('restaurant_report_recipients')->nullOnDelete();
            $table->string('recipient_name_snapshot', 100)->nullable();
            $table->text('recipient_phone_e164')->nullable();
            $table->string('recipient_phone_masked', 32)->nullable();
            $table->char('recipient_phone_hash', 64)->nullable();
            $table->string('template_name', 512)->nullable();
            $table->string('template_language', 16)->nullable();
            $table->string('provider_message_id', 255)->nullable()->unique();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name_snapshot')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->timestamp('sending_started_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_day_close_id', 'id']);
        });

        DB::statement("ALTER TABLE restaurant_day_close_deliveries ADD CONSTRAINT restaurant_day_close_deliveries_kind_check CHECK (kind IN ('automatic', 'manual_resend'))");
        DB::statement("ALTER TABLE restaurant_day_close_deliveries ADD CONSTRAINT restaurant_day_close_deliveries_status_check CHECK (status IN ('pending', 'accepted', 'sent', 'delivered', 'read', 'failed', 'skipped'))");
        DB::statement("ALTER TABLE restaurant_day_close_deliveries ADD CONSTRAINT restaurant_day_close_deliveries_channel_check CHECK (channel IN ('whatsapp'))");
        // At most ONE automatic delivery per close, enforced by the database.
        DB::statement("CREATE UNIQUE INDEX restaurant_day_close_deliveries_one_automatic ON restaurant_day_close_deliveries (restaurant_day_close_id) WHERE kind = 'automatic'");
        DB::statement('CREATE UNIQUE INDEX restaurant_day_close_deliveries_idempotency ON restaurant_day_close_deliveries (restaurant_day_close_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_day_close_deliveries');
    }
};

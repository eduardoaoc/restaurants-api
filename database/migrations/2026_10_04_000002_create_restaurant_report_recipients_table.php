<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who receives the Cierre Diario report by WhatsApp (CARTA 9.1E). One
     * ACTIVE recipient per restaurant and channel (partial unique). A
     * changed phone number is a NEW row (the old one is deactivated), so
     * consent never silently migrates to another number and past
     * deliveries keep pointing at the recipient they were sent to.
     *
     * phone_e164 is encrypted at rest (Laravel `encrypted` cast, APP_KEY);
     * phone_hash is an HMAC-SHA256 keyed with APP_KEY (a plain SHA-256 of
     * a phone number is trivially brute-forced); phone_masked is the only
     * form ever displayed or audited.
     */
    public function up(): void
    {
        Schema::create('restaurant_report_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16)->default('whatsapp');
            $table->string('name', 100);
            $table->text('phone_e164');
            $table->char('phone_hash', 64);
            $table->string('phone_masked', 32);
            $table->boolean('active')->default(true);
            $table->timestamp('consent_given_at')->nullable();
            $table->foreignId('consent_recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('consent_method', 32)->nullable();
            $table->string('consent_text_version', 16)->nullable();
            $table->timestamp('consent_revoked_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'channel']);
        });

        DB::statement("ALTER TABLE restaurant_report_recipients ADD CONSTRAINT restaurant_report_recipients_channel_check CHECK (channel IN ('whatsapp'))");
        DB::statement('CREATE UNIQUE INDEX restaurant_report_recipients_one_active ON restaurant_report_recipients (restaurant_id, channel) WHERE active');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_report_recipients');
    }
};

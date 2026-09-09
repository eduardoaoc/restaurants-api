<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusing AuditLog for platform-level events (rather than a duplicate
     * table) means organization_id can no longer be guaranteed: a
     * platform.user.* event about a user who belongs to zero/several
     * organizations has no single unambiguous organization_id to attach.
     * restaurant_id was already nullable for the same reason at the
     * tenant level; this brings organization_id in line for platform
     * events only — every existing tenant call site keeps passing a real
     * organization_id, so no existing row or behavior changes.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable(false)->change();
        });
    }
};

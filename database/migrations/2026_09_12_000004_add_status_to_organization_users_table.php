<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passo 2.8B-FIX: a user's operational membership in ONE organization
     * (active/inactive — see OrganizationUser::STATUSES) is a distinct
     * concept from users.status (active/suspended — a GLOBAL, platform-only
     * flag; see User::STATUSES and EnsureUserIsActive). Reusing users.status
     * for tenant-level staff deactivation let a tenant owner/manager
     * accidentally revert a platform-level suspension by PATCHing a staff
     * member back to "active" — this column gives tenants their own,
     * strictly weaker authority that can never touch users.status.
     *
     * Defaults every existing row to 'active' so no current membership is
     * affected by this migration.
     */
    public function up(): void
    {
        Schema::table('organization_users', function (Blueprint $table) {
            $table->string('status')->default('active')->index()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('organization_users', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};

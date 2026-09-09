<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bloco 0 (Platform Super Admin foundation): a User account can be
     * suspended by a platform admin independently of any tenant role or
     * organization membership — see User::STATUS_* and
     * EnsureUserIsActive. Defaults every existing row to 'active' so
     * nobody is locked out by this migration.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active')->index()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};

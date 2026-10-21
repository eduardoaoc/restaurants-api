<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Anonymous QR entry has no staff actor. Preserve the foreign key.
        DB::statement('ALTER TABLE table_sessions ALTER COLUMN opened_by_user_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Intentionally fails if public sessions still exist: never fabricate
        // a staff actor or delete visit history to make a rollback succeed.
        DB::statement('ALTER TABLE table_sessions ALTER COLUMN opened_by_user_id SET NOT NULL');
    }
};

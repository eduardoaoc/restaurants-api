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
        Schema::table('table_sessions', function (Blueprint $table) {
            // Generated once, inside RecordPaymentAction, at the exact
            // moment payment_status flips to 'paid' (see
            // TableSession::generateUniqueFeedbackToken()). Not derived
            // from the id — the same unpredictable-token pattern as
            // tables.public_token — and is the ONLY key the public
            // feedback endpoints accept; table_session_id/table_id/
            // public_token are never sufficient on their own (Passo 3.5).
            $table->string('feedback_token')->nullable()->unique()->after('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->dropColumn('feedback_token');
        });
    }
};

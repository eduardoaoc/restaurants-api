<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_feedbacks', function (Blueprint $table) {
            $table->id();

            // Historical record, like payment_records/staff_reviews: never
            // cascade-delete a customer's feedback out of existence.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();

            // One feedback per visit (Passo 3.5 §4) — enforced here, not
            // just in application code, so a race between two concurrent
            // submissions for the same session can't create two rows.
            $table->foreignId('table_session_id')->unique()->constrained('table_sessions')->restrictOnDelete();

            // Snapshot of TableSession::assigned_waiter_user_id AT
            // SUBMISSION TIME — a plain nullable column, never recomputed,
            // so a later waiter reassignment can never rewrite an already
            // submitted feedback's attribution (Passo 3.5 §9). Null when
            // the visit had no waiter reliably assignable; feedback is
            // still accepted.
            $table->foreignId('waiter_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('first_name', 100);
            $table->string('last_name', 100);

            $table->unsignedTinyInteger('wait_time_rating');
            $table->unsignedTinyInteger('food_rating');
            $table->unsignedTinyInteger('service_rating');
            $table->unsignedTinyInteger('overall_rating');

            $table->string('experience_comment', 1000)->nullable();
            $table->string('improvement_comment', 1000)->nullable();
            $table->string('contact', 150)->nullable();

            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['restaurant_id', 'submitted_at']);
            $table->index(['waiter_id', 'submitted_at']);
        });

        // Ratings are 1-5 — enforced at the database level too, not just
        // by the public FormRequest, the same defense-in-depth already
        // used elsewhere for domain invariants that must never be violated
        // regardless of which code path writes the row.
        DB::statement(
            'ALTER TABLE customer_feedbacks ADD CONSTRAINT customer_feedbacks_ratings_between_1_and_5 CHECK ('
            .'wait_time_rating BETWEEN 1 AND 5 AND '
            .'food_rating BETWEEN 1 AND 5 AND '
            .'service_rating BETWEEN 1 AND 5 AND '
            .'overall_rating BETWEEN 1 AND 5'
            .')'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_feedbacks');
    }
};

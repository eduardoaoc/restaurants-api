<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Cierre Diario (CARTA 9.1A): one definitive, immutable close of a
     * restaurant's operational period [period_started_at, period_ended_at).
     * Periods chain without gaps or overlap (each one starts exactly where
     * the previous one ended — see DayClosePeriodResolver).
     *
     * Columns hold what history lists/filters on; `report` holds the full
     * versioned snapshot (report_schema_version) with report_sha256 over
     * its canonical JSON. Nothing here is ever updated by the application;
     * later corrections are restaurant_day_close_annotations rows. No
     * `status` column: "completed" is the only state that exists.
     */
    public function up(): void
    {
        Schema::create('restaurant_day_closes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();

            $table->date('business_date');
            $table->date('business_date_from');
            $table->timestamp('period_started_at');
            $table->timestamp('period_ended_at');
            $table->string('timezone', 64);
            $table->string('currency', 3);

            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_by_name_snapshot');
            $table->timestamp('closed_at');

            $table->decimal('total_received', 12, 2);
            $table->decimal('cash_received', 12, 2);
            $table->decimal('card_received', 12, 2);
            $table->decimal('other_received', 12, 2);
            $table->unsignedInteger('payments_count');
            $table->unsignedInteger('sessions_with_payments');
            $table->decimal('average_ticket', 12, 2);

            $table->decimal('opening_float', 12, 2);
            $table->decimal('cash_pay_ins', 12, 2);
            $table->decimal('cash_pay_outs', 12, 2);
            $table->decimal('expected_cash', 12, 2);
            $table->decimal('counted_cash', 12, 2);
            $table->decimal('cash_difference', 12, 2);
            $table->decimal('cash_left_for_next_day', 12, 2)->nullable();
            $table->string('cash_difference_note', 500)->nullable();

            $table->unsignedInteger('orders_registered');
            $table->unsignedInteger('orders_valid');
            $table->unsignedInteger('orders_served');
            $table->unsignedInteger('orders_rejected');
            $table->unsignedInteger('sessions_opened');
            $table->unsignedInteger('sessions_closed');
            $table->unsignedInteger('guests');

            $table->unsignedInteger('feedback_count');
            $table->decimal('feedback_avg_overall', 3, 2)->nullable();
            $table->unsignedInteger('critical_feedback_count');
            $table->unsignedInteger('low_dimension_feedback_count');
            $table->unsignedInteger('delays_count');
            $table->unsignedInteger('unavailable_products_count');

            $table->text('notes')->nullable();
            $table->boolean('has_incidents');

            // json (not jsonb): stored verbatim, so the snapshot reads back
            // with exactly the structure/key order it was written with.
            // Nothing queries inside it — filters use the columns above.
            $table->json('report');
            $table->unsignedSmallInteger('report_schema_version');
            $table->char('report_sha256', 64);

            $table->string('idempotency_key', 100);
            $table->char('payload_hash', 64);
            $table->timestamps();

            $table->unique(['restaurant_id', 'business_date']);
            $table->unique(['restaurant_id', 'period_started_at']);
            $table->unique(['restaurant_id', 'idempotency_key']);
            $table->index(['restaurant_id', 'closed_by_user_id']);
            $table->index(['restaurant_id', 'has_incidents', 'business_date']);
        });

        DB::statement('ALTER TABLE restaurant_day_closes ADD CONSTRAINT restaurant_day_closes_period_check CHECK (period_ended_at > period_started_at)');
        DB::statement('ALTER TABLE restaurant_day_closes ADD CONSTRAINT restaurant_day_closes_business_dates_check CHECK (business_date_from <= business_date)');
        DB::statement(
            'ALTER TABLE restaurant_day_closes ADD CONSTRAINT restaurant_day_closes_money_non_negative CHECK ('
            .'total_received >= 0 AND cash_received >= 0 AND card_received >= 0 AND other_received >= 0 AND average_ticket >= 0 AND '
            .'opening_float >= 0 AND cash_pay_ins >= 0 AND cash_pay_outs >= 0 AND expected_cash >= 0 AND counted_cash >= 0 AND '
            .'(cash_left_for_next_day IS NULL OR cash_left_for_next_day >= 0))'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_day_closes');
    }
};

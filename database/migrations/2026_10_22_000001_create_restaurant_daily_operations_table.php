<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The operational day of a restaurant: explicitly opened (opening float
     * declared) and finished by the existing Cierre Diario, which links
     * itself through day_close_id. At most ONE open operation per restaurant
     * (partial unique index) and one operation per business date — the same
     * key restaurant_day_closes already enforces, so an operation can always
     * be closed by exactly one close.
     *
     * table_sessions.daily_operation_id ties new sessions (and through them
     * their orders and payments) to the operation. Existing sessions stay
     * NULL: nothing is attributed retroactively, no operation is fabricated.
     */
    public function up(): void
    {
        Schema::create('restaurant_daily_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();

            $table->date('business_date');
            $table->string('timezone', 64);
            $table->string('currency', 3);
            $table->string('status', 16);

            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('opened_by_name_snapshot');
            $table->timestamp('opened_at');
            $table->decimal('opening_float', 12, 2);
            // What the existing precedence suggested at opening (previous
            // close's cash_left_for_next_day / restaurant default / none),
            // kept to audit a declared float that differs from it.
            $table->decimal('suggested_opening_float', 12, 2)->nullable();
            $table->string('suggested_opening_float_source', 32);

            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_by_name_snapshot')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('day_close_id')->nullable()->unique()->constrained('restaurant_day_closes')->restrictOnDelete();

            $table->string('idempotency_key', 100);
            $table->char('payload_hash', 64);
            $table->timestamps();

            $table->unique(['restaurant_id', 'business_date']);
            $table->unique(['restaurant_id', 'idempotency_key']);
        });

        DB::statement("CREATE UNIQUE INDEX restaurant_daily_operations_one_open ON restaurant_daily_operations (restaurant_id) WHERE status = 'open'");
        DB::statement("ALTER TABLE restaurant_daily_operations ADD CONSTRAINT restaurant_daily_operations_status_check CHECK (status IN ('open', 'closed'))");
        DB::statement('ALTER TABLE restaurant_daily_operations ADD CONSTRAINT restaurant_daily_operations_money_check CHECK (opening_float >= 0 AND (suggested_opening_float IS NULL OR suggested_opening_float >= 0))');
        DB::statement(
            'ALTER TABLE restaurant_daily_operations ADD CONSTRAINT restaurant_daily_operations_lifecycle_check CHECK ('
            ."(status = 'open' AND closed_at IS NULL AND day_close_id IS NULL) OR "
            ."(status = 'closed' AND closed_at IS NOT NULL AND closed_at >= opened_at AND day_close_id IS NOT NULL))"
        );

        Schema::table('table_sessions', function (Blueprint $table) {
            $table->foreignId('daily_operation_id')->nullable()->after('restaurant_id')
                ->constrained('restaurant_daily_operations')->restrictOnDelete();
            $table->index(['daily_operation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('daily_operation_id');
        });

        Schema::dropIfExists('restaurant_daily_operations');
    }
};

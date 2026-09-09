<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimal AFORO SaaS subscription state on Organization — deliberately
     * not a `plans`/`subscriptions` domain: no billing/Stripe integration
     * exists yet in this codebase (see the Bloco 0 report's Plans gap).
     * These two columns are the foundation a platform admin needs today
     * (view/correct plan and subscription state administratively) without
     * inventing a fictitious billing system. Distinct from Restaurant's
     * PaymentRecord, which is a customer-facing table bill, not a SaaS
     * subscription.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('plan')->default('free')->after('status');
            $table->string('subscription_status')->default('active')->index()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['plan', 'subscription_status']);
        });
    }
};

<?php

namespace App\Support\Billing;

use App\Models\PaymentRecord;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Sums PaymentRecord.amount for one Restaurant over a half-open time range
 * — the single shared implementation of "money actually collected" (never
 * Order totals, never unpaid balances). Extracted so
 * RestaurantDashboardService (period sales) and the Operations Live
 * snapshot (Bloco 5 — sales.received_today) never carry two separate
 * implementations of the same SUM query. Returns integer cents so each
 * caller can do its own further math (e.g. average_ticket) without a
 * decimal round-trip.
 */
class PaymentsSummary
{
    public static function totalCents(int $restaurantId, CarbonImmutable $from, CarbonImmutable $toExclusive): int
    {
        $total = PaymentRecord::query()
            ->where('restaurant_id', $restaurantId)
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<', $toExclusive)
            ->sum('amount');

        return Money::decimalToCents((string) $total);
    }

    /**
     * The count of DISTINCT table sessions with at least one payment in
     * the period — including a partially paid one, not only fully-settled
     * sessions (see RestaurantDashboardService's own docblock on this same
     * definition, now shared with the Analytics API — Bloco 6).
     */
    public static function sessionsWithPaymentsCount(int $restaurantId, CarbonImmutable $from, CarbonImmutable $toExclusive): int
    {
        return (int) PaymentRecord::query()
            ->where('restaurant_id', $restaurantId)
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<', $toExclusive)
            ->distinct('table_session_id')
            ->count('table_session_id');
    }

    /**
     * The average_ticket formula consolidated across the app: revenue
     * received divided by the distinct number of sessions that received
     * at least one payment — rounded to the nearest cent, "0.00" (never
     * null/division-by-zero) when there are no such sessions. Deliberately
     * NOT revenue / closed_sessions — see the Bloco 6 report.
     */
    public static function averageTicketCents(int $totalCents, int $sessionsWithPayments): int
    {
        return $sessionsWithPayments > 0 ? (int) round($totalCents / $sessionsWithPayments) : 0;
    }
}

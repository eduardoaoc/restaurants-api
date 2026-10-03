<?php

namespace App\Support\Analytics;

use App\Models\PaymentRecord;
use App\Models\Restaurant;
use App\Support\Billing\PaymentsSummary;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Revenue = PaymentRecord actually received (see PaymentsSummary) — never
 * Order totals, never an open balance. summary() reuses the exact same
 * shared helpers as RestaurantDashboardService, so the two can never
 * disagree on what "revenue"/"average ticket" mean (Bloco 6).
 *
 * series() buckets by the Restaurant's OWN local calendar, not UTC: the
 * stored `recorded_at` is a UTC wall-clock value, reinterpreted via
 * `AT TIME ZONE 'UTC' AT TIME ZONE ?` (the standard Postgres idiom for
 * "this naive timestamp actually holds a UTC instant — give me its local
 * wall-clock representation in this other zone") before date_trunc groups
 * it. Postgres's `date_trunc('week', ...)` is Monday-anchored (ISO 8601),
 * matching AnalyticsBucketBuilder's own explicit Monday convention, so the
 * two produce identical keys to zero-fill against — one query, however
 * many buckets are requested (never one query per bucket).
 */
class RevenueAnalytics
{
    /**
     * @return array{revenue: string, average_ticket: string, sessions_with_payments: int}
     */
    public static function summary(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $totalCents = PaymentsSummary::totalCents($restaurant->id, $from, $toExclusive);
        $sessionsWithPayments = PaymentsSummary::sessionsWithPaymentsCount($restaurant->id, $from, $toExclusive);

        return [
            'revenue' => Money::centsToDecimal($totalCents),
            'average_ticket' => Money::centsToDecimal(PaymentsSummary::averageTicketCents($totalCents, $sessionsWithPayments)),
            'sessions_with_payments' => $sessionsWithPayments,
        ];
    }

    /**
     * @return array<int, array{period_start: string, revenue: string}>
     */
    public static function series(
        Restaurant $restaurant,
        CarbonImmutable $from,
        CarbonImmutable $toExclusive,
        string $fromDate,
        string $toDate,
        string $granularity,
        string $timezone,
    ): array {
        $rows = PaymentRecord::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<', $toExclusive)
            ->selectRaw(
                "to_char(date_trunc(?, recorded_at AT TIME ZONE 'UTC' AT TIME ZONE ?), 'YYYY-MM-DD') as bucket_key, COALESCE(SUM(amount), 0) as total_amount",
                [$granularity, $timezone]
            )
            // Postgres explicitly permits grouping by a SELECT-list alias
            // (unlike strict ANSI SQL) — grouping by the raw date_trunc(...)
            // expression again here hits a Postgres grouping-error quirk
            // when combined with the selectRaw's own bindings; the alias
            // form is simpler and verified working (see the Bloco 6 report).
            ->groupBy('bucket_key')
            ->get()
            ->keyBy('bucket_key');

        $keys = AnalyticsBucketBuilder::buildKeys($fromDate, $toDate, $granularity);

        return array_map(function (string $key) use ($rows) {
            $row = $rows->get($key);
            $cents = $row ? Money::decimalToCents((string) $row->total_amount) : 0;

            return [
                'period_start' => $key,
                'revenue' => Money::centsToDecimal($cents),
            ];
        }, $keys);
    }
}

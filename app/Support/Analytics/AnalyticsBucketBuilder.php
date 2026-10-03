<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Generates the ordered, zero-filled list of bucket keys for a
 * day/week/month time series (Bloco 6) — reused by every series in the
 * Analytics response (currently revenue_series) so day/week/month bucket
 * logic is never reimplemented per metric.
 *
 * Each key is the bucket's own LOCAL start date ("Y-m-d") in the
 * Restaurant's calendar — week buckets start on MONDAY (explicit, not
 * dependent on any global Carbon week-start config) to match Postgres's
 * own `date_trunc('week', ...)` semantics (also ISO/Monday-based), which
 * is what the SQL side of every series query uses to group rows — the two
 * must produce identical keys for zero-fill to line up. A week/month
 * bucket's key can fall BEFORE the requested `from` (e.g. `from` on a
 * Wednesday still buckets under that week's Monday) — this is the correct,
 * expected alignment with date_trunc, not a bug; the same is true for the
 * final bucket extending past `to`.
 */
class AnalyticsBucketBuilder
{
    /**
     * @return array<int, string>
     */
    public static function buildKeys(string $fromDate, string $toDate, string $granularity): array
    {
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $toDate);
        $bucketStart = self::bucketStartFor(CarbonImmutable::createFromFormat('!Y-m-d', $fromDate), $granularity);

        $keys = [];

        while ($bucketStart->lte($end)) {
            $keys[] = $bucketStart->toDateString();
            $bucketStart = self::nextBucketStart($bucketStart, $granularity);
        }

        return $keys;
    }

    private static function bucketStartFor(CarbonImmutable $date, string $granularity): CarbonImmutable
    {
        return match ($granularity) {
            'week' => $date->startOfWeek(CarbonInterface::MONDAY),
            'month' => $date->startOfMonth(),
            default => $date,
        };
    }

    private static function nextBucketStart(CarbonImmutable $bucketStart, string $granularity): CarbonImmutable
    {
        return match ($granularity) {
            'week' => $bucketStart->addWeek(),
            'month' => $bucketStart->addMonthNoOverflow(),
            default => $bucketStart->addDay(),
        };
    }
}

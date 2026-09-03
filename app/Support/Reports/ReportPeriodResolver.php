<?php

namespace App\Support\Reports;

use App\Exceptions\Reports\InvalidReportPeriodException;
use Carbon\CarbonImmutable;

/**
 * Resolves the ?from=&to= query pair for operational report endpoints
 * (currently: the restaurant dashboard) into a half-open date range:
 * `>= from` and `< toExclusive` (toExclusive is the day after `to`).
 * Half-open avoids any edge case around end-of-day microseconds — a row
 * timestamped exactly at midnight of the day after `to` is unambiguously
 * excluded.
 *
 * Both dates are required together (a lone `from` or `to` is rejected) and
 * default to the current calendar month when neither is given. Maximum
 * span is 366 days.
 *
 * Deliberately a standalone class rather than a reuse of
 * Staff\PerformancePeriodResolver: the rules are identical (see Bloco 17
 * report), but the two endpoint families are unrelated and a shared
 * dependency would couple staff-performance's exception type
 * (InvalidPerformancePeriodException) to reports, or vice versa, for no
 * real benefit.
 */
class ReportPeriodResolver
{
    private const MAX_DAYS = 366;

    /**
     * @return array{from: CarbonImmutable, toExclusive: CarbonImmutable, fromLabel: string, toLabel: string}
     */
    public static function resolve(?string $from, ?string $to): array
    {
        if (($from === null) !== ($to === null)) {
            throw new InvalidReportPeriodException;
        }

        if ($from === null) {
            $start = CarbonImmutable::now()->startOfMonth();
            $end = CarbonImmutable::now()->endOfMonth()->startOfDay();
        } else {
            $start = self::parseDate($from);
            $end = self::parseDate($to);
        }

        if ($end->lt($start)) {
            throw new InvalidReportPeriodException;
        }

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new InvalidReportPeriodException;
        }

        return [
            'from' => $start,
            'toExclusive' => $end->addDay(),
            'fromLabel' => $start->toDateString(),
            'toLabel' => $end->toDateString(),
        ];
    }

    private static function parseDate(string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidReportPeriodException;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            throw new InvalidReportPeriodException;
        }

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidReportPeriodException;
        }

        return $date->startOfDay();
    }
}

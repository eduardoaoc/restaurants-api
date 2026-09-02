<?php

namespace App\Support\Staff;

use App\Exceptions\Staff\InvalidPerformancePeriodException;
use Carbon\CarbonImmutable;

/**
 * Resolves the ?from=&to= query pair for staff performance endpoints into
 * a half-open date range: `>= from` and `< toExclusive` (toExclusive is
 * the day after `to`). Half-open avoids any edge case around end-of-day
 * microseconds — a row timestamped exactly at midnight of the day after
 * `to` is unambiguously excluded.
 *
 * Both dates are required together (a lone `from` or `to` is rejected) and
 * default to the current calendar month when neither is given. Maximum
 * span is 366 days.
 */
class PerformancePeriodResolver
{
    private const MAX_DAYS = 366;

    /**
     * @return array{from: CarbonImmutable, toExclusive: CarbonImmutable, fromLabel: string, toLabel: string}
     */
    public static function resolve(?string $from, ?string $to): array
    {
        if (($from === null) !== ($to === null)) {
            throw new InvalidPerformancePeriodException;
        }

        if ($from === null) {
            $start = CarbonImmutable::now()->startOfMonth();
            $end = CarbonImmutable::now()->endOfMonth()->startOfDay();
        } else {
            $start = self::parseDate($from);
            $end = self::parseDate($to);
        }

        if ($end->lt($start)) {
            throw new InvalidPerformancePeriodException;
        }

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new InvalidPerformancePeriodException;
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
            throw new InvalidPerformancePeriodException;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            throw new InvalidPerformancePeriodException;
        }

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidPerformancePeriodException;
        }

        return $date->startOfDay();
    }
}

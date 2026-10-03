<?php

namespace App\Support\Analytics;

use App\Exceptions\Analytics\InvalidAnalyticsPeriodException;
use App\Models\Restaurant;
use App\Support\Restaurants\RestaurantClock;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Resolves the Analytics endpoint's ?from=&to=&granularity= into a
 * timezone-aware period (Bloco 6) — the one place this project's
 * historical-report endpoints do NOT already handle correctly:
 * ReportPeriodResolver (used by /dashboard) parses from/to and defaults
 * to "current month" using the SERVER's default timezone (UTC), never
 * RestaurantSettings.timezone. This class fixes that for Analytics
 * specifically, without touching /dashboard's already-tested behavior —
 * see the Bloco 6 report.
 *
 * `from`/`to` are calendar dates in the Restaurant's OWN local timezone;
 * fromUtc/toExclusiveUtc are the matching UTC instants for querying
 * timestamp columns (always stored/interpreted as UTC — see
 * config/app.php). Maximum span is 366 days, same limit as every other
 * period resolver in this codebase.
 */
class AnalyticsPeriodResolver
{
    private const MAX_DAYS = 366;

    /**
     * @var array<int, string>
     */
    public const GRANULARITIES = ['day', 'week', 'month'];

    public const DEFAULT_GRANULARITY = 'day';

    /**
     * @return array{fromDate: string, toDate: string, fromUtc: CarbonImmutable, toExclusiveUtc: CarbonImmutable, granularity: string, timezone: string}
     */
    public static function resolve(Restaurant $restaurant, ?string $from, ?string $to, ?string $granularity): array
    {
        $timezone = $restaurant->settings->timezone;
        $resolvedGranularity = $granularity ?? self::DEFAULT_GRANULARITY;

        if (! in_array($resolvedGranularity, self::GRANULARITIES, true)) {
            throw new InvalidAnalyticsPeriodException;
        }

        if (($from === null) !== ($to === null)) {
            throw new InvalidAnalyticsPeriodException;
        }

        if ($from === null) {
            $localNow = CarbonImmutable::now($timezone);
            $fromDate = $localNow->startOfMonth()->toDateString();
            $toDate = $localNow->endOfMonth()->toDateString();
        } else {
            $fromDate = self::parseDate($from);
            $toDate = self::parseDate($to);
        }

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $toDate);

        if ($end->lt($start)) {
            throw new InvalidAnalyticsPeriodException;
        }

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new InvalidAnalyticsPeriodException;
        }

        return [
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'fromUtc' => RestaurantClock::localDateStartUtc($restaurant, $fromDate),
            'toExclusiveUtc' => RestaurantClock::localDateStartUtc($restaurant, $end->addDay()->toDateString()),
            'granularity' => $resolvedGranularity,
            'timezone' => $timezone,
        ];
    }

    private static function parseDate(string $value): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidAnalyticsPeriodException;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            throw new InvalidAnalyticsPeriodException;
        }

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidAnalyticsPeriodException;
        }

        return $value;
    }
}

<?php

namespace App\Support\DayClose;

use App\Models\Restaurant;
use App\Models\RestaurantDayClose;
use Carbon\CarbonImmutable;

/**
 * Resolves the operational period a Cierre Diario closes (CARTA 9.1A).
 * Deliberately NOT ReportPeriodResolver (the Dashboard's, UTC-naive) nor
 * AnalyticsPeriodResolver (calendar days): a business day here crosses
 * midnight.
 *
 *   - Periods chain without gaps or overlap: period_started_at is the
 *     previous close's period_ended_at. The very first close starts at
 *     the cutoff instant of its own business date — earlier activity is
 *     simply outside the closing system (no infinite history import).
 *   - period_ended_at is T, the instant the caller captured (the close
 *     captures it right after taking the exclusive operational lock).
 *   - business_date: the restaurant-local date of T, minus one day when
 *     T's local wall-clock time is before business_day_cutoff_time (a
 *     close at 01:59 belongs to the previous day; at 06:00 to the new
 *     one, with the default 06:00 cutoff).
 *   - business_date_from: the business day right after the previous
 *     close's (or business_date itself on the first close) — when it is
 *     before business_date, the period covers days nobody closed.
 *
 * All instants are UTC; only the business-date arithmetic uses the
 * restaurant timezone, so DST changes durations correctly (real elapsed
 * time). A cutoff falling in a DST gap is moved forward by Carbon.
 */
class DayClosePeriodResolver
{
    public function resolve(Restaurant $restaurant, CarbonImmutable $endUtc): DayClosePeriod
    {
        $settings = $restaurant->settings;
        $timezone = $settings->timezone;
        $cutoff = $settings->business_day_cutoff_time;

        $previous = RestaurantDayClose::query()
            ->where('restaurant_id', $restaurant->id)
            ->orderByDesc('period_ended_at')
            ->first();

        $businessDate = self::businessDateOf($endUtc, $timezone, $cutoff);

        if ($previous === null) {
            $start = self::cutoffInstantUtc($businessDate, $timezone, $cutoff);
            $businessDateFrom = $businessDate;
        } else {
            $start = CarbonImmutable::instance($previous->period_ended_at)->utc();
            $nextDay = CarbonImmutable::createFromFormat('!Y-m-d', $previous->business_date->format('Y-m-d'))->addDay()->toDateString();
            $businessDateFrom = min($nextDay, $businessDate);
        }

        return new DayClosePeriod(
            startUtc: $start,
            endUtc: $endUtc,
            businessDate: $businessDate,
            businessDateFrom: $businessDateFrom,
            timezone: $timezone,
            firstClose: $previous === null,
            previousClose: $previous,
            businessDateAlreadyClosed: $previous !== null && $businessDate <= $previous->business_date->format('Y-m-d'),
        );
    }

    /**
     * The business date (Y-m-d) an instant belongs to.
     */
    public static function businessDateOf(CarbonImmutable $instantUtc, string $timezone, string $cutoff): string
    {
        $local = $instantUtc->setTimezone($timezone);

        return $local->format('H:i') < $cutoff
            ? $local->subDay()->toDateString()
            : $local->toDateString();
    }

    /**
     * The UTC instant at which business date $date starts (its local
     * cutoff time).
     */
    public static function cutoffInstantUtc(string $date, string $timezone, string $cutoff): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$cutoff}", $timezone)->utc();
    }
}

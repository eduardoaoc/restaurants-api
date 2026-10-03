<?php

namespace App\Support\Restaurants;

use App\Models\Restaurant;
use Carbon\CarbonImmutable;

/**
 * Resolves "now" and "start of today" for a Restaurant's own local
 * timezone (RestaurantSettings.timezone — e.g. Europe/Madrid), never the
 * server/app timezone (always UTC — see config/app.php). Used by the
 * Operations Live snapshot's sales.received_today (Bloco 5): a payment
 * recorded at 23:50 Europe/Madrid must count as "today" even though the
 * underlying timestamp is stored in UTC, and a payment just after local
 * midnight must NOT count as yesterday.
 *
 * Every value returned here is already converted to UTC (see utc() calls)
 * so it compares correctly against timestamp columns, which are stored
 * and interpreted in UTC throughout this application.
 */
class RestaurantClock
{
    public static function nowUtc(Restaurant $restaurant): CarbonImmutable
    {
        return CarbonImmutable::now($restaurant->settings->timezone)->utc();
    }

    public static function startOfTodayUtc(Restaurant $restaurant): CarbonImmutable
    {
        return CarbonImmutable::now($restaurant->settings->timezone)->startOfDay()->utc();
    }

    /**
     * The UTC instant corresponding to local midnight (00:00) of the given
     * "Y-m-d" calendar date, interpreted in the Restaurant's own timezone
     * — the general form of startOfTodayUtc() for an arbitrary date
     * (Bloco 6: Analytics' ?from=/?to=). $localDate is never validated
     * here (format/existence) — callers (AnalyticsPeriodResolver) are
     * expected to have already validated it.
     */
    public static function localDateStartUtc(Restaurant $restaurant, string $localDate): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $localDate, $restaurant->settings->timezone)->utc();
    }
}

<?php

namespace App\Support\Analytics;

use App\Models\Restaurant;
use App\Models\TableSession;
use Carbon\CarbonImmutable;

/**
 * Peak hours = TableSessions STARTED per local hour-of-day (Bloco 6) —
 * represents table arrivals/occupancy, the chosen definition for "busiest
 * time" (as opposed to orders_created, a plausible alternative — see the
 * Bloco 6 report). Always all 24 local hours, zero-filled, one query.
 */
class PeakHourAnalytics
{
    /**
     * @return array<int, array{hour: int, sessions_started: int}>
     */
    public static function compute(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive, string $timezone): array
    {
        $rows = TableSession::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('opened_at', '>=', $from)
            ->where('opened_at', '<', $toExclusive)
            ->selectRaw(
                "EXTRACT(HOUR FROM opened_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::int as local_hour, COUNT(*) as session_count",
                [$timezone]
            )
            ->groupBy('local_hour')
            ->get()
            ->keyBy('local_hour');

        $result = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $row = $rows->get($hour);

            $result[] = [
                'hour' => $hour,
                'sessions_started' => $row ? (int) $row->session_count : 0,
            ];
        }

        return $result;
    }
}

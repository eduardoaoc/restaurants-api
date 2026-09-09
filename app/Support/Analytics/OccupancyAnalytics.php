<?php

namespace App\Support\Analytics;

use App\Models\Restaurant;
use App\Models\Table;
use App\Models\TableSession;
use App\Support\Operations\ElapsedTime;
use Carbon\CarbonImmutable;

/**
 * Historical occupancy + table turnover + session duration for one
 * Restaurant and period (Bloco 6) — built from TableSession.opened_at/
 * closed_at only, never from the Table's CURRENT state (that's
 * Operations Live's job, Bloco 5).
 *
 * Denominator caveat (documented, not hidden): the number of "available"
 * tables uses the restaurant's CURRENT Table count. This project has no
 * historical record of when a Table was created/removed precise enough to
 * reconstruct exactly how many tables existed at every instant of an
 * arbitrary past period — inventing that precision would be worse than
 * being explicit about the approximation. For a restaurant whose table
 * count hasn't changed during the queried period (the common case) this
 * is exact; for one that added/removed tables mid-period it is an
 * approximation. See the Bloco 6 report.
 */
class OccupancyAnalytics
{
    /**
     * Loads every TableSession that OVERLAPS [from, toExclusive) in one
     * query (never one query per table/day) and sums the overlap in PHP —
     * the row count for one restaurant over a realistic reporting window
     * (weeks/months) is small enough that this is both simpler and safer
     * than a raw-SQL GREATEST/LEAST clamp, and avoids a second subtly
     * different implementation of the same overlap math.
     *
     * @return array{occupancy_rate: float, occupied_seconds: int, total_tables: int, table_turnover: array{closed_sessions: int, turnover_per_table: float}, average_session_duration_seconds: ?int}
     */
    public static function compute(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $totalTables = Table::query()->where('restaurant_id', $restaurant->id)->count();

        $overlappingSessions = TableSession::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('opened_at', '<', $toExclusive)
            ->where(function ($query) use ($from) {
                $query->whereNull('closed_at')->orWhere('closed_at', '>', $from);
            })
            ->get(['opened_at', 'closed_at']);

        $occupiedSeconds = 0;

        foreach ($overlappingSessions as $session) {
            $effectiveStart = $session->opened_at->greaterThan($from) ? $session->opened_at : $from;
            $sessionEnd = $session->closed_at ?? $toExclusive;
            $effectiveEnd = $sessionEnd->lessThan($toExclusive) ? $sessionEnd : $toExclusive;

            if ($effectiveEnd->greaterThan($effectiveStart)) {
                $occupiedSeconds += ElapsedTime::seconds($effectiveEnd, $effectiveStart);
            }
        }

        $rangeSeconds = ElapsedTime::seconds($toExclusive, $from);
        $availableTableSeconds = $totalTables * $rangeSeconds;

        $closedInPeriod = TableSession::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'closed')
            ->where('closed_at', '>=', $from)
            ->where('closed_at', '<', $toExclusive)
            ->selectRaw('COUNT(*) as closed_count, AVG(EXTRACT(EPOCH FROM (closed_at - opened_at))) as avg_duration')
            ->first();

        $closedSessions = (int) $closedInPeriod->closed_count;

        return [
            'occupancy_rate' => $availableTableSeconds > 0 ? round($occupiedSeconds / $availableTableSeconds, 4) : 0.0,
            'occupied_seconds' => $occupiedSeconds,
            'total_tables' => $totalTables,
            'table_turnover' => [
                'closed_sessions' => $closedSessions,
                'turnover_per_table' => $totalTables > 0 ? round($closedSessions / $totalTables, 2) : 0.0,
            ],
            'average_session_duration_seconds' => $closedInPeriod->avg_duration !== null
                ? (int) round((float) $closedInPeriod->avg_duration)
                : null,
        ];
    }
}

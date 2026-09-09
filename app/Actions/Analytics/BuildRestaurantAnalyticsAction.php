<?php

namespace App\Actions\Analytics;

use App\Models\Restaurant;
use App\Models\TableSession;
use App\Support\Analytics\AnalyticsPeriodResolver;
use App\Support\Analytics\KitchenAnalytics;
use App\Support\Analytics\OccupancyAnalytics;
use App\Support\Analytics\OrderAnalytics;
use App\Support\Analytics\PeakHourAnalytics;
use App\Support\Analytics\ProductAnalytics;
use App\Support\Analytics\RevenueAnalytics;
use App\Support\Analytics\StaffAnalytics;
use Carbon\CarbonImmutable;

/**
 * Builds the historical Analytics read model for one Restaurant + period
 * (Bloco 6) — read-only, no writes, no AuditLog, nothing persisted or
 * cached. Each section is delegated to its own dedicated class (revenue,
 * occupancy/turnover, peak hours, products, kitchen, orders, staff), every
 * one of which loads its own data with a FIXED number of queries
 * regardless of period length or how many tables/staff/products are
 * involved — see each class's own docblock and the Bloco 6 report's
 * Performance section for the exact strategy per section.
 *
 * Deliberately excludes anything from Operations Live (Bloco 5): no
 * current table status, no alerts, no health score, no bottleneck — this
 * is history, not "right now".
 */
class BuildRestaurantAnalyticsAction
{
    public function __construct(private readonly StaffAnalytics $staffAnalytics) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(Restaurant $restaurant, ?string $from, ?string $to, ?string $granularity): array
    {
        $restaurant->loadMissing('settings');

        $period = AnalyticsPeriodResolver::resolve($restaurant, $from, $to, $granularity);
        $fromUtc = $period['fromUtc'];
        $toExclusiveUtc = $period['toExclusiveUtc'];

        $revenueSummary = RevenueAnalytics::summary($restaurant, $fromUtc, $toExclusiveUtc);
        $occupancy = OccupancyAnalytics::compute($restaurant, $fromUtc, $toExclusiveUtc);
        $orders = OrderAnalytics::compute($restaurant, $fromUtc, $toExclusiveUtc);

        return [
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'timezone' => $period['timezone'],
                'currency' => $restaurant->settings->currency,
            ],
            'period' => [
                'from' => $period['fromDate'],
                'to' => $period['toDate'],
                'granularity' => $period['granularity'],
                'timezone' => $period['timezone'],
            ],
            'summary' => [
                'revenue' => $revenueSummary['revenue'],
                'average_ticket' => $revenueSummary['average_ticket'],
                'sessions_with_payments' => $revenueSummary['sessions_with_payments'],
                'orders_count' => $orders['total'],
                'guests_served' => $occupancy['table_turnover']['closed_sessions'] > 0
                    ? $this->guestsServed($restaurant, $fromUtc, $toExclusiveUtc)
                    : 0,
                'closed_sessions' => $occupancy['table_turnover']['closed_sessions'],
            ],
            'revenue_series' => RevenueAnalytics::series(
                $restaurant,
                $fromUtc,
                $toExclusiveUtc,
                $period['fromDate'],
                $period['toDate'],
                $period['granularity'],
                $period['timezone'],
            ),
            'occupancy' => [
                'occupancy_rate' => $occupancy['occupancy_rate'],
                'occupied_seconds' => $occupancy['occupied_seconds'],
                'total_tables' => $occupancy['total_tables'],
                'average_session_duration_seconds' => $occupancy['average_session_duration_seconds'],
            ],
            'table_turnover' => $occupancy['table_turnover'],
            'peak_hours' => PeakHourAnalytics::compute($restaurant, $fromUtc, $toExclusiveUtc, $period['timezone']),
            'products' => ProductAnalytics::topProducts($restaurant, $fromUtc, $toExclusiveUtc),
            'kitchen' => KitchenAnalytics::compute($restaurant, $fromUtc, $toExclusiveUtc),
            'orders' => $orders,
            'staff' => $this->staffAnalytics->compute($restaurant, $restaurant->organization_id, $fromUtc, $toExclusiveUtc),
        ];
    }

    /**
     * guests_served = SUM(guest_count) of sessions CLOSED within the
     * period — "attendance concluded", the same choice already
     * established for Operations Live's active_guests being a live
     * snapshot vs this being a historical count (see item 58/report).
     * One query, only run when there is at least one closed session to
     * avoid a pointless query on an empty period.
     */
    private function guestsServed(Restaurant $restaurant, CarbonImmutable $fromUtc, CarbonImmutable $toExclusiveUtc): int
    {
        return (int) TableSession::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'closed')
            ->where('closed_at', '>=', $fromUtc)
            ->where('closed_at', '<', $toExclusiveUtc)
            ->sum('guest_count');
    }
}

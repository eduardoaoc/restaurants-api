<?php

namespace App\Support\Analytics;

use App\Models\Order;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;

/**
 * Historical order counts + origin distribution for one Restaurant/period
 * (Bloco 6). `total`/`by_origin` count every Order CREATED in the period
 * regardless of its eventual status — the same "created" definition
 * RestaurantDashboardService already uses — so a customer_qr order that
 * was later rejected still counts as having been placed. Origins are
 * exactly Order::ORIGIN_* — never invented.
 */
class OrderAnalytics
{
    /**
     * @var array<int, string>
     */
    private const ORIGINS = [
        Order::ORIGIN_CUSTOMER_QR,
        Order::ORIGIN_WAITER,
        Order::ORIGIN_MANAGER,
        Order::ORIGIN_CASHIER,
    ];

    /**
     * @return array{total: int, by_origin: array<string, int>}
     */
    public static function compute(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $rows = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->select('origin')
            ->selectRaw('COUNT(*) as origin_count')
            ->groupBy('origin')
            ->get()
            ->keyBy('origin');

        $byOrigin = [];
        $total = 0;

        foreach (self::ORIGINS as $origin) {
            $count = $rows->has($origin) ? (int) $rows->get($origin)->origin_count : 0;
            $byOrigin[$origin] = $count;
            $total += $count;
        }

        return [
            'total' => $total,
            'by_origin' => $byOrigin,
        ];
    }
}

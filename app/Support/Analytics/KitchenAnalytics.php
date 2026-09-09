<?php

namespace App\Support\Analytics;

use App\Models\Order;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;

/**
 * Kitchen metrics computed ONLY from timestamps that are actually reliable
 * (Bloco 6): every Order transition is stamped atomically with its own
 * `{status}_at` column by TransitionOrderStatusAction, and each transition
 * strictly requires the exact preceding status (accepted requires
 * confirmed, preparing requires accepted, ready requires preparing — see
 * that Action). So any order whose ready_at is set is GUARANTEED to also
 * have preparing_at set — average_preparation_time_seconds
 * (ready_at - preparing_at) is therefore a real, reliable measurement,
 * not a guess derived from a borrowed timestamp like updated_at.
 *
 * There is no confirmed_at column (a staff order is created already
 * confirmed; a customer order's equivalent moment is approved_at) and no
 * "average time to accept/serve" is computed here — only the one
 * transition (preparing -> ready) this domain can measure precisely.
 */
class KitchenAnalytics
{
    /**
     * @return array{orders_created: int, orders_ready: int, orders_cancelled: int, average_preparation_time_seconds: ?int}
     */
    public static function compute(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $ordersCreated = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->count();

        $ordersReady = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereNotNull('ready_at')
            ->where('ready_at', '>=', $from)
            ->where('ready_at', '<', $toExclusive)
            ->count();

        $ordersCancelled = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', Order::STATUS_CANCELLED)
            ->where('cancelled_at', '>=', $from)
            ->where('cancelled_at', '<', $toExclusive)
            ->count();

        $preparation = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereNotNull('preparing_at')
            ->whereNotNull('ready_at')
            ->where('ready_at', '>=', $from)
            ->where('ready_at', '<', $toExclusive)
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (ready_at - preparing_at))) as avg_seconds')
            ->first();

        return [
            'orders_created' => $ordersCreated,
            'orders_ready' => $ordersReady,
            'orders_cancelled' => $ordersCancelled,
            'average_preparation_time_seconds' => $preparation->avg_seconds !== null
                ? (int) round((float) $preparation->avg_seconds)
                : null,
        ];
    }
}

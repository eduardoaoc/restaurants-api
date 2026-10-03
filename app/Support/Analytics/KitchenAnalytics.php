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
 * There is no confirmed_at column, but the confirmation moment is still
 * exact: a customer order that needed approval is confirmed at
 * approved_at (approve is the only way out of waiting_approval), and
 * every other order (staff, or auto-confirmed customer) is created
 * already confirmed, at created_at. Likewise served requires ready, so
 * served_at - ready_at is exact. timings() (CARTA 7.1A) exposes those
 * three transitions; nothing here ever reads updated_at.
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

        return [
            'orders_created' => $ordersCreated,
            'orders_ready' => $ordersReady,
            'orders_cancelled' => $ordersCancelled,
            'average_preparation_time_seconds' => self::timings($restaurant, $from, $toExclusive)['preparation']['average_seconds'],
        ];
    }

    /**
     * The three measurable kitchen transitions, each averaged over the
     * orders whose END timestamp falls in [$from, $toExclusive), with its
     * sample size (0 => average null, never 0 seconds):
     *
     *   accept          accepted_at - COALESCE(approved_at, created_at)
     *                   (confirmed -> taken by the kitchen; the approval
     *                   wait of a customer order is NOT kitchen time)
     *   preparation     ready_at - preparing_at
     *   ready_to_served served_at - ready_at (how long a ready order
     *                   waited for a waiter to pick it up)
     *
     * One aggregate query (FILTER clauses), restaurant-scoped.
     *
     * @return array{accept: array{average_seconds: ?int, orders: int}, preparation: array{average_seconds: ?int, orders: int}, ready_to_served: array{average_seconds: ?int, orders: int}}
     */
    public static function timings(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $in = fn (string $column) => "{$column} >= ? AND {$column} < ?";
        $bindings = [$from, $toExclusive];

        $row = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where(fn ($query) => $query
                ->whereBetween('accepted_at', [$from, $toExclusive])
                ->orWhereBetween('ready_at', [$from, $toExclusive])
                ->orWhereBetween('served_at', [$from, $toExclusive]))
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (accepted_at - COALESCE(approved_at, created_at)))) FILTER (WHERE '.$in('accepted_at').') AS accept_avg, '.
                'COUNT(*) FILTER (WHERE '.$in('accepted_at').') AS accept_n, '.
                'AVG(EXTRACT(EPOCH FROM (ready_at - preparing_at))) FILTER (WHERE preparing_at IS NOT NULL AND '.$in('ready_at').') AS preparation_avg, '.
                'COUNT(*) FILTER (WHERE preparing_at IS NOT NULL AND '.$in('ready_at').') AS preparation_n, '.
                'AVG(EXTRACT(EPOCH FROM (served_at - ready_at))) FILTER (WHERE ready_at IS NOT NULL AND '.$in('served_at').') AS ready_to_served_avg, '.
                'COUNT(*) FILTER (WHERE ready_at IS NOT NULL AND '.$in('served_at').') AS ready_to_served_n',
                [...$bindings, ...$bindings, ...$bindings, ...$bindings, ...$bindings, ...$bindings],
            )
            ->first();

        $timing = fn (string $key) => [
            'average_seconds' => $row->{"{$key}_avg"} !== null ? (int) round((float) $row->{"{$key}_avg"}) : null,
            'orders' => (int) $row->{"{$key}_n"},
        ];

        return [
            'accept' => $timing('accept'),
            'preparation' => $timing('preparation'),
            'ready_to_served' => $timing('ready_to_served'),
        ];
    }
}

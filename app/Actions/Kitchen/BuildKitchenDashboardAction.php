<?php

namespace App\Actions\Kitchen;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Analytics\KitchenAnalytics;
use App\Support\Analytics\ProductAnalytics;
use App\Support\Operations\ElapsedTime;
use App\Support\Restaurants\RestaurantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read model for the Kitchen Dashboard (CARTA 7.1A) — read-only, nothing
 * persisted or cached, a FIXED number of queries whatever the volume.
 *
 * Why it carries its own small queue summary instead of pointing the
 * client at /operations/live: kitchen staff hold update_kitchen_status
 * only — neither view_operations (live snapshot) nor view_reports
 * (analytics) — so they could not compose those endpoints. The queue
 * summary is also deliberately the KITCHEN queue (confirmed → ready,
 * Order::kitchenQueueStatuses(), same as GET /kitchen/orders), not the
 * snapshot's open orders (which include waiting_approval, not kitchen
 * work yet). The item-level queue itself stays GET /kitchen/orders.
 *
 * Historical parts (timings, top products, recent lists) cover "today"
 * in the restaurant's own timezone (RestaurantClock), up to now. Every
 * duration comes from the lifecycle's own exact timestamps — see
 * KitchenAnalytics::timings(); never updated_at.
 */
class BuildKitchenDashboardAction
{
    public const RECENT_LIMIT = 5;

    public const TOP_PRODUCTS_LIMIT = 5;

    /**
     * @return array<string, mixed>
     */
    public function execute(Restaurant $restaurant): array
    {
        $restaurant->loadMissing('settings');

        $now = CarbonImmutable::now();
        $from = RestaurantClock::startOfTodayUtc($restaurant);
        // Half-open bound just past "now", same reasoning as the live
        // snapshot's received_today (an event stamped this very second
        // must count).
        $toExclusive = RestaurantClock::nowUtc($restaurant)->addSecond();

        $recentAccepted = $this->recent($restaurant, 'accepted_at', $from, $toExclusive);
        $recentReady = $this->recent($restaurant, 'ready_at', $from, $toExclusive);
        [$itemsByOrder, $usersById] = $this->loadRecentDetails($recentAccepted->merge($recentReady));

        return [
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'timezone' => $restaurant->settings->timezone,
            ],
            'generated_at' => $now,
            'period' => ['from' => $from, 'to' => $now],
            'queue' => $this->queue($restaurant, $now),
            'timings' => KitchenAnalytics::timings($restaurant, $from, $toExclusive),
            'top_products' => ProductAnalytics::topByQuantity($restaurant, $from, $toExclusive, self::TOP_PRODUCTS_LIMIT),
            'recent_accepted' => $recentAccepted->map(fn (Order $order) => $this->recentView($order, 'accepted', $itemsByOrder, $usersById))->all(),
            'recent_ready' => $recentReady->map(fn (Order $order) => $this->recentView($order, 'ready', $itemsByOrder, $usersById) + [
                'served_at' => $order->served_at,
            ])->all(),
        ];
    }

    /**
     * Live kitchen queue: counts per kitchen status, the oldest order's
     * age (now - created_at) and the longest current ready wait
     * (now - ready_at of the oldest still-ready order). One grouped query.
     *
     * @return array<string, mixed>
     */
    private function queue(Restaurant $restaurant, CarbonImmutable $now): array
    {
        $rows = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', Order::kitchenQueueStatuses())
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS total, MIN(created_at) AS oldest_created_at, MIN(ready_at) AS oldest_ready_at')
            ->get()
            ->keyBy('status');

        $counts = [];
        foreach (Order::kitchenQueueStatuses() as $status) {
            $counts[$status] = (int) ($rows->get($status)?->total ?? 0);
        }

        $oldestCreatedAt = $rows->pluck('oldest_created_at')->filter()->min();
        $oldestReadyAt = $rows->get(Order::STATUS_READY)?->oldest_ready_at;

        return [
            'counts_by_status' => $counts,
            'active_orders' => array_sum($counts),
            'oldest_active_order_age_seconds' => $oldestCreatedAt !== null
                ? ElapsedTime::seconds($now, CarbonImmutable::parse($oldestCreatedAt))
                : null,
            'longest_ready_wait_seconds' => $oldestReadyAt !== null
                ? ElapsedTime::seconds($now, CarbonImmutable::parse($oldestReadyAt))
                : null,
        ];
    }

    /**
     * @return Collection<int, Order>
     */
    private function recent(Restaurant $restaurant, string $column, CarbonImmutable $from, CarbonImmutable $toExclusive): Collection
    {
        return Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where($column, '>=', $from)
            ->where($column, '<', $toExclusive)
            ->with('table:id,name')
            ->orderByDesc($column)
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get(['id', 'table_id', 'status', 'accepted_at', 'accepted_by_user_id', 'ready_at', 'ready_by_user_id', 'served_at']);
    }

    /**
     * Items and actor names for both recent lists in two queries total.
     *
     * @param  Collection<int, Order>  $orders
     * @return array{0: Collection<int, Collection<int, OrderItem>>, 1: Collection<int, User>}
     */
    private function loadRecentDetails(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [collect(), collect()];
        }

        $items = OrderItem::query()
            ->whereIn('order_id', $orders->pluck('id')->unique())
            ->orderBy('id')
            ->get(['id', 'order_id', 'product_name_snapshot', 'quantity'])
            ->groupBy('order_id');

        $userIds = $orders->pluck('accepted_by_user_id')->merge($orders->pluck('ready_by_user_id'))->filter()->unique();
        $users = $userIds->isEmpty() ? collect() : User::query()->whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');

        return [$items, $users];
    }

    /**
     * @param  'accepted'|'ready'  $step
     * @return array<string, mixed>
     */
    private function recentView(Order $order, string $step, Collection $itemsByOrder, Collection $usersById): array
    {
        $actorId = $order->{"{$step}_by_user_id"};
        $actor = $actorId !== null ? $usersById->get($actorId) : null;

        return [
            'order' => ['id' => $order->id, 'reference' => sprintf('#%d', $order->id)],
            'table' => ['id' => $order->table->id, 'name' => $order->table->name],
            'status' => $order->status,
            "{$step}_at" => $order->{"{$step}_at"},
            "{$step}_by" => $actor ? ['id' => $actor->id, 'name' => $actor->name] : null,
            'items' => $itemsByOrder->get($order->id, collect())
                ->map(fn (OrderItem $item) => ['name' => $item->product_name_snapshot, 'quantity' => $item->quantity])
                ->values()
                ->all(),
        ];
    }
}

<?php

namespace App\Support\Analytics;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Top products sold, computed EXCLUSIVELY from OrderItem's own snapshot
 * columns (product_name_snapshot, line_total_snapshot) — never by joining
 * to the live Product row (Bloco 6): a renamed/repriced/deleted Product
 * must never rewrite what analytics says was actually sold in the past.
 * order_items.product_id is nullable (nullOnDelete — see the migration),
 * so a deleted Product is still fully representable via its snapshot.
 *
 * Grouped by (product_id, product_name_snapshot): the same still-existing
 * product is grouped together as long as its snapshot name did not change
 * mid-period (a rename mid-period is a real, accepted MVP edge case — it
 * would split into two rows rather than being silently merged or
 * misattributed; documented, not hidden — see the Bloco 6 report).
 *
 * Reference timestamp is Order.created_at (not Payment — a product is
 * "sold" when ordered, not when eventually paid — see item 54) and only
 * Order::billableStatuses() count (never a cancelled/waiting_approval
 * order — see item 55), the same canonical rule used by SessionBillCalculator.
 */
class ProductAnalytics
{
    private const LIMIT = 10;

    /**
     * @return array{top_by_quantity: array<int, array{id: ?int, name: string, quantity: int, revenue: string}>, top_by_revenue: array<int, array{id: ?int, name: string, quantity: int, revenue: string}>}
     */
    public static function topProducts(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $baseQuery = fn () => OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.restaurant_id', $restaurant->id)
            ->where('orders.created_at', '>=', $from)
            ->where('orders.created_at', '<', $toExclusive)
            ->whereIn('orders.status', Order::billableStatuses())
            ->groupBy('order_items.product_id', 'order_items.product_name_snapshot')
            ->selectRaw(
                'order_items.product_id as product_id, order_items.product_name_snapshot as product_name, '.
                'SUM(order_items.quantity) as total_quantity, SUM(order_items.line_total_snapshot) as total_revenue'
            );

        $byQuantity = $baseQuery()->orderByDesc('total_quantity')->limit(self::LIMIT)->get();
        $byRevenue = $baseQuery()->orderByDesc('total_revenue')->limit(self::LIMIT)->get();

        return [
            'top_by_quantity' => self::mapRows($byQuantity),
            'top_by_revenue' => self::mapRows($byRevenue),
        ];
    }

    /**
     * @return array<int, array{id: ?int, name: string, quantity: int, revenue: string}>
     */
    private static function mapRows(Collection $rows): array
    {
        return $rows->map(fn ($row) => [
            'id' => $row->product_id !== null ? (int) $row->product_id : null,
            'name' => $row->product_name,
            'quantity' => (int) $row->total_quantity,
            'revenue' => Money::centsToDecimal(Money::decimalToCents((string) $row->total_revenue)),
        ])->values()->all();
    }
}

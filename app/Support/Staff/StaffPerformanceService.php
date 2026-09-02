<?php

namespace App\Support\Staff;

use App\Models\Order;
use App\Models\StaffReview;
use App\Models\TableRequest;
use App\Models\TableSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Computes objective operational metrics and the (separate, subjective)
 * rating summary for one staff member, scoped to an explicit set of
 * restaurant ids and a half-open date range. Every query is a SQL
 * aggregate (COUNT / COUNT DISTINCT / AVG) — never a collection loaded
 * into PHP to be counted there.
 *
 * $restaurantIds is always passed explicitly by the caller (never derived
 * here from "all restaurants the actor could reach"): for an operational
 * self/target it's that person's one restaurant; for an organization-level
 * self (owner) it's every restaurant of the active organization. Either
 * way, restaurant_id is always in the WHERE clause — never just
 * actor_user_id — so results can never leak across organizations even if
 * a user id were somehow reused.
 */
class StaffPerformanceService
{
    /**
     * @param  array<int, int>  $restaurantIds
     * @return array{tables_served: int, orders_created: int, orders_served: int, customer_orders_approved: int, table_requests_handled: int, sessions_closed: int}
     */
    public function metrics(array $restaurantIds, int $staffUserId, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        if ($restaurantIds === []) {
            return [
                'tables_served' => 0,
                'orders_created' => 0,
                'orders_served' => 0,
                'customer_orders_approved' => 0,
                'table_requests_handled' => 0,
                'sessions_closed' => 0,
            ];
        }

        return [
            'tables_served' => Order::query()
                ->whereIn('restaurant_id', $restaurantIds)
                ->where('served_by_user_id', $staffUserId)
                ->where('served_at', '>=', $from)
                ->where('served_at', '<', $toExclusive)
                ->distinct()
                ->count('table_session_id'),
            'orders_created' => Order::query()
                ->whereIn('restaurant_id', $restaurantIds)
                ->where('created_by_user_id', $staffUserId)
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $toExclusive)
                ->count(),
            'orders_served' => Order::query()
                ->whereIn('restaurant_id', $restaurantIds)
                ->where('served_by_user_id', $staffUserId)
                ->where('served_at', '>=', $from)
                ->where('served_at', '<', $toExclusive)
                ->count(),
            'customer_orders_approved' => Order::query()
                ->whereIn('restaurant_id', $restaurantIds)
                ->where('approved_by_user_id', $staffUserId)
                ->where('approved_at', '>=', $from)
                ->where('approved_at', '<', $toExclusive)
                ->count(),
            'table_requests_handled' => TableRequest::query()
                ->whereIn('restaurant_id', $restaurantIds)
                ->where('completed_by_user_id', $staffUserId)
                ->where('completed_at', '>=', $from)
                ->where('completed_at', '<', $toExclusive)
                ->count(),
            'sessions_closed' => TableSession::query()
                ->whereIn('restaurant_id', $restaurantIds)
                ->where('closed_by_user_id', $staffUserId)
                ->where('closed_at', '>=', $from)
                ->where('closed_at', '<', $toExclusive)
                ->count(),
        ];
    }

    /**
     * @param  array<int, int>  $restaurantIds
     * @return array{average: ?string, review_count: int}
     */
    public function rating(array $restaurantIds, int $organizationId, int $staffUserId, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        if ($restaurantIds === []) {
            return ['average' => null, 'review_count' => 0];
        }

        $row = StaffReview::query()
            ->where('organization_id', $organizationId)
            ->whereIn('restaurant_id', $restaurantIds)
            ->where('staff_user_id', $staffUserId)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->select([
                DB::raw('COUNT(*) as review_count'),
                // Rounded server-side (Postgres NUMERIC), never through PHP
                // float arithmetic — same "no float" discipline as Money.
                DB::raw('ROUND(AVG(rating)::numeric, 2) as average'),
            ])
            ->first();

        $reviewCount = (int) $row->review_count;

        return [
            'average' => $reviewCount > 0 ? (string) $row->average : null,
            'review_count' => $reviewCount,
        ];
    }
}

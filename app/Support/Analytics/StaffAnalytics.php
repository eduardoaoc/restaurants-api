<?php

namespace App\Support\Analytics;

use App\Models\Restaurant;
use App\Models\StaffShift;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Operations\ElapsedTime;
use App\Support\Staff\StaffPerformanceService;
use Carbon\CarbonImmutable;

/**
 * Historical staff section of Analytics (Bloco 6) — who was PRESENT
 * (StaffShift overlap with the period, same overlap math as
 * OccupancyAnalytics) plus their objective StaffPerformanceService
 * metrics and StaffReview ratings for the same window, all fetched in
 * bulk (never one query set per staff member).
 *
 * The staff LIST itself is defined by StaffShift presence, not by order/
 * request activity: a staff member who somehow acted without ever
 * starting a shift in the period (Bloco 3 does not hard-require an active
 * shift for these actions) will not appear here — a deliberate, documented
 * scope choice, not an oversight (see the Bloco 6 report).
 *
 * Deliberately OMITS sales_attributed and guests_served: neither can be
 * attributed to a specific waiter with real historical precision.
 * TableSession.assigned_waiter_user_id is the FINAL/current responsible
 * waiter, not a reassignment-aware history (see Bloco 2/Bloco 6 report) —
 * unlike served_by_user_id (a per-order, point-in-time fact, exactly who
 * served THAT order), attributing a whole session's guests/revenue to
 * "whoever is assigned now" would silently misattribute every
 * reassigned session. Precedent set by item 38's own explicit preference.
 */
class StaffAnalytics
{
    public function __construct(private readonly StaffPerformanceService $performanceService) {}

    /**
     * @return array<int, array{user: array{id: int, name: ?string}, role: ?string, shift_count: int, active_seconds: int, tables_served: int, orders_created: int, orders_served: int, customer_orders_approved: int, table_requests_handled: int, sessions_closed: int, average_rating: ?string, reviews_count: int}>
     */
    public function compute(Restaurant $restaurant, int $organizationId, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $overlappingShifts = StaffShift::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('started_at', '<', $toExclusive)
            ->where(function ($query) use ($from) {
                $query->whereNull('ended_at')->orWhere('ended_at', '>', $from);
            })
            ->get(['user_id', 'started_at', 'ended_at']);

        if ($overlappingShifts->isEmpty()) {
            return [];
        }

        $shiftsByUser = $overlappingShifts->groupBy('user_id');
        $staffUserIds = $shiftsByUser->keys()->all();

        $usersById = User::query()->whereIn('id', $staffUserIds)->get()->keyBy('id');

        $rolesByUserId = UserRole::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('user_id', $staffUserIds)
            ->with('role')
            ->get()
            ->keyBy('user_id');

        $metricsByUserId = $this->performanceService->metricsForUsers([$restaurant->id], $staffUserIds, $from, $toExclusive);
        $ratingsByUserId = $this->performanceService->ratingsForUsers([$restaurant->id], $organizationId, $staffUserIds, $from, $toExclusive);

        $result = [];

        foreach ($shiftsByUser as $userId => $shifts) {
            $activeSeconds = 0;

            foreach ($shifts as $shift) {
                $effectiveStart = $shift->started_at->greaterThan($from) ? $shift->started_at : $from;
                $shiftEnd = $shift->ended_at ?? $toExclusive;
                $effectiveEnd = $shiftEnd->lessThan($toExclusive) ? $shiftEnd : $toExclusive;

                if ($effectiveEnd->greaterThan($effectiveStart)) {
                    $activeSeconds += ElapsedTime::seconds($effectiveEnd, $effectiveStart);
                }
            }

            $metrics = $metricsByUserId[$userId] ?? [
                'tables_served' => 0, 'orders_created' => 0, 'orders_served' => 0,
                'customer_orders_approved' => 0, 'table_requests_handled' => 0, 'sessions_closed' => 0,
            ];
            $rating = $ratingsByUserId[$userId] ?? ['average' => null, 'review_count' => 0];

            $result[] = [
                'user' => [
                    'id' => $userId,
                    'name' => $usersById->get($userId)?->name,
                ],
                'role' => $rolesByUserId->get($userId)?->role?->slug,
                'shift_count' => $shifts->count(),
                'active_seconds' => $activeSeconds,
                'tables_served' => $metrics['tables_served'],
                'orders_created' => $metrics['orders_created'],
                'orders_served' => $metrics['orders_served'],
                'customer_orders_approved' => $metrics['customer_orders_approved'],
                'table_requests_handled' => $metrics['table_requests_handled'],
                'sessions_closed' => $metrics['sessions_closed'],
                'average_rating' => $rating['average'],
                'reviews_count' => $rating['review_count'],
            ];
        }

        return $result;
    }
}

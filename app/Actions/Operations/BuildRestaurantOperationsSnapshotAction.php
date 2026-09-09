<?php

namespace App\Actions\Operations;

use App\Models\Floor;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\StaffShift;
use App\Models\Table;
use App\Models\TableRequest;
use App\Models\User;
use App\Models\UserRole;
use App\Models\WaiterCall;
use App\Models\Zone;
use App\Support\Billing\PaymentsSummary;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;
use App\Support\Operations\ElapsedTime;
use App\Support\Operations\OperationAlertBuilder;
use App\Support\Operations\OperationHealthCalculator;
use App\Support\Operations\OperationsBottleneckResolver;
use App\Support\Operations\TableOperationalStateResolver;
use App\Support\Restaurants\RestaurantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the Operations Live snapshot for one Restaurant (Bloco 5) — a
 * read-only, read-model aggregation, never a write. Every number is
 * derived at call time from Floor/Zone/Table, TableSession, Order,
 * TableRequest, WaiterCall, PaymentRecord and StaffShift; nothing here is
 * persisted (no migrations, no cache — see the report).
 *
 * Deliberately loads its inputs with a small, FIXED number of queries
 * regardless of how many tables/sessions/staff/orders the restaurant has
 * (see the report's Performance section for the exact count) — every
 * per-table/per-session/per-staff derivation below runs entirely against
 * already-loaded PHP collections, never a query inside a loop.
 */
class BuildRestaurantOperationsSnapshotAction
{
    /**
     * @return array<string, mixed>
     */
    public function execute(Restaurant $restaurant): array
    {
        $restaurant->loadMissing('settings');

        $now = CarbonImmutable::now();

        $floors = Floor::query()->where('restaurant_id', $restaurant->id)->orderBy('sort_order')->get();
        $zones = Zone::query()->where('restaurant_id', $restaurant->id)->orderBy('sort_order')->get();
        $tables = Table::query()->where('restaurant_id', $restaurant->id)->with('activeSession')->get();

        $activeSessions = $tables->pluck('activeSession')->filter()->values();
        $activeSessionIds = $activeSessions->pluck('id')->all();

        $activeShifts = StaffShift::query()->where('restaurant_id', $restaurant->id)->whereNull('ended_at')->get();
        $activeShiftUserIds = $activeShifts->pluck('user_id')->unique()->values()->all();

        $assignedWaiterIds = $activeSessions->pluck('assigned_waiter_user_id')->filter()->unique()->values();
        $allRelevantUserIds = $assignedWaiterIds->merge($activeShiftUserIds)->unique()->values();

        $usersById = $allRelevantUserIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $allRelevantUserIds)->get()->keyBy('id');

        $restaurantUsersByUserId = $allRelevantUserIds->isEmpty()
            ? collect()
            : RestaurantUser::query()->where('restaurant_id', $restaurant->id)->whereIn('user_id', $allRelevantUserIds)->get()->keyBy('user_id');

        $rolesByUserId = empty($activeShiftUserIds)
            ? collect()
            : UserRole::query()->where('restaurant_id', $restaurant->id)->whereIn('user_id', $activeShiftUserIds)->with('role')->get()->keyBy('user_id');

        $openOrders = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', Order::openStatuses())
            ->get(['id', 'table_id', 'table_session_id', 'status', 'created_at']);
        $openOrdersBySession = $openOrders->groupBy('table_session_id');

        $billingBySession = SessionBillCalculator::summarizeMany($activeSessionIds);

        $pendingTableRequests = TableRequest::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', TableRequest::STATUS_PENDING)
            ->get(['id', 'table_id', 'table_session_id', 'type', 'created_at']);
        $pendingTableRequestsBySession = $pendingTableRequests->groupBy('table_session_id');

        $pendingWaiterCalls = WaiterCall::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', WaiterCall::STATUS_PENDING)
            ->get(['id', 'table_session_id', 'waiter_user_id', 'created_at']);
        $pendingWaiterCallsBySession = $pendingWaiterCalls->groupBy('table_session_id');

        // +1 second: PaymentsSummary::totalCents's upper bound is exclusive
        // (the shared half-open-range contract used everywhere else in
        // this codebase, e.g. the historical dashboard's own periods,
        // where the bound is always some future "start of next day").
        // Here the bound is "now" itself, so a payment recorded in the
        // very same instant the snapshot is generated must still count as
        // received today — a strict `< now` would drop it.
        $receivedTodayCents = PaymentsSummary::totalCents(
            $restaurant->id,
            RestaurantClock::startOfTodayUtc($restaurant),
            RestaurantClock::nowUtc($restaurant)->addSecond(),
        );

        $tableViews = $tables->map(fn (Table $table) => $this->buildTableView(
            $table,
            $openOrdersBySession,
            $billingBySession,
            $pendingTableRequestsBySession,
            $pendingWaiterCallsBySession,
            $usersById,
            $restaurantUsersByUserId,
            $activeShiftUserIds,
            $now,
        ))->all();

        [$floorViews, $unassignedTableViews] = $this->buildFloorTree($floors, $zones, $tableViews);

        $staffViews = $this->buildStaffViews(
            $activeShifts,
            $usersById,
            $rolesByUserId,
            $activeSessions,
            $pendingTableRequestsBySession,
            $pendingWaiterCallsBySession,
            $now,
        );

        $ordersByStatusCounts = [];
        foreach (Order::openStatuses() as $status) {
            $ordersByStatusCounts[$status] = $openOrders->where('status', $status)->count();
        }

        $alerts = OperationAlertBuilder::build(
            $activeSessions,
            $usersById,
            $activeShiftUserIds,
            $pendingTableRequests,
            $pendingWaiterCalls,
            $openOrders,
            $now,
        );

        $healthScore = OperationHealthCalculator::score($alerts);

        $totalTables = $tables->count();
        $occupiedTables = $activeSessions->count();

        return [
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'timezone' => $restaurant->settings->timezone,
                'currency' => $restaurant->settings->currency,
            ],
            'generated_at' => $now,
            'summary' => [
                'tables' => [
                    'total' => $totalTables,
                    'free' => $totalTables - $occupiedTables,
                    'occupied' => $occupiedTables,
                    'occupancy_rate' => $totalTables > 0 ? round($occupiedTables / $totalTables, 4) : 0.0,
                ],
                'active_sessions' => $occupiedTables,
                'active_guests' => (int) $activeSessions->sum('guest_count'),
                'orders' => [
                    'active' => $openOrders->count(),
                    'waiting_approval' => $ordersByStatusCounts[Order::STATUS_WAITING_APPROVAL] ?? 0,
                    'preparing' => $ordersByStatusCounts[Order::STATUS_PREPARING] ?? 0,
                    'ready' => $ordersByStatusCounts[Order::STATUS_READY] ?? 0,
                ],
                'staff' => ['active' => $activeShifts->count()],
                'requests' => ['pending' => $pendingTableRequests->count()],
                'sales' => ['received_today' => Money::centsToDecimal($receivedTodayCents)],
            ],
            'operation' => [
                'health_score' => $healthScore,
                'health_level' => OperationHealthCalculator::level($healthScore),
                'bottleneck' => OperationsBottleneckResolver::resolve($alerts),
            ],
            'floors' => $floorViews,
            'unassigned_tables' => $unassignedTableViews,
            'staff' => $staffViews,
            'kitchen' => [
                'counts_by_status' => $ordersByStatusCounts,
                'active_orders' => $openOrders->count(),
                'oldest_active_order_age_seconds' => $openOrders->isEmpty()
                    ? null
                    : ElapsedTime::seconds($now, $openOrders->min('created_at')),
            ],
            'alerts' => $alerts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTableView(
        Table $table,
        Collection $openOrdersBySession,
        array $billingBySession,
        Collection $pendingTableRequestsBySession,
        Collection $pendingWaiterCallsBySession,
        Collection $usersById,
        Collection $restaurantUsersByUserId,
        array $activeShiftUserIds,
        CarbonImmutable $now,
    ): array {
        $layout = [
            'x' => $table->layout_x,
            'y' => $table->layout_y,
            'rotation' => $table->layout_rotation,
            'shape' => $table->layout_shape,
            'width' => $table->layout_width,
            'height' => $table->layout_height,
        ];

        $base = [
            'id' => $table->id,
            'name' => $table->name,
            'number' => $table->number,
            'capacity' => $table->capacity,
            'zone_id' => $table->zone_id,
            'layout' => $layout,
        ];

        $session = $table->activeSession;

        if (! $session) {
            $state = TableOperationalStateResolver::resolve($this->emptyState());

            return $base + [
                'primary_status' => $state['primary_status'],
                'flags' => $state['flags'],
                'session' => null,
                'orders' => ['open_count' => 0, 'waiting_approval' => 0, 'preparing' => 0, 'ready' => 0],
                'billing' => null,
            ];
        }

        $sessionOpenOrders = $openOrdersBySession->get($session->id, collect());
        $hasWaitingApproval = $sessionOpenOrders->contains('status', Order::STATUS_WAITING_APPROVAL);
        $hasReady = $sessionOpenOrders->contains('status', Order::STATUS_READY);
        $preparingBucket = [Order::STATUS_CONFIRMED, Order::STATUS_ACCEPTED, Order::STATUS_PREPARING];
        $hasPreparing = $sessionOpenOrders->whereIn('status', $preparingBucket)->isNotEmpty();

        $pendingRequestsForSession = $pendingTableRequestsBySession->get($session->id, collect());
        $hasBillRequested = $pendingRequestsForSession->contains('type', TableRequest::TYPE_REQUEST_BILL);
        $hasWaiterRequested = $pendingRequestsForSession->contains('type', TableRequest::TYPE_CALL_WAITER);
        $hasWaiterCallPending = $pendingWaiterCallsBySession->get($session->id, collect())->isNotEmpty();

        $waiterId = $session->assigned_waiter_user_id;
        $waiter = $waiterId !== null ? $usersById->get($waiterId) : null;
        $isSuspended = $waiter?->isSuspended() ?? false;
        $isOffShift = $waiterId !== null && ! $isSuspended && ! in_array($waiterId, $activeShiftUserIds, true);

        $state = TableOperationalStateResolver::resolve([
            'has_active_session' => true,
            'has_bill_requested' => $hasBillRequested,
            'has_waiter_requested' => $hasWaiterRequested,
            'has_responsible_waiter_called' => $hasWaiterCallPending,
            'has_ready_order' => $hasReady,
            'has_waiting_approval_order' => $hasWaitingApproval,
            'has_preparing_order' => $hasPreparing,
            'is_unassigned' => $waiterId === null,
            'assigned_waiter_off_shift' => $isOffShift,
            'assigned_waiter_suspended' => $isSuspended,
        ]);

        $billing = $billingBySession[$session->id] ?? null;
        $billingView = null;

        if ($billing && $billing['hasBillableOrders']) {
            $billingView = [
                'total' => Money::centsToDecimal($billing['ordersTotalCents']),
                'paid' => Money::centsToDecimal($billing['paidTotalCents']),
                'outstanding' => Money::centsToDecimal($billing['balanceCents']),
                'status' => match (true) {
                    $billing['balanceCents'] <= 0 => 'paid',
                    $billing['paidTotalCents'] > 0 => 'partial',
                    default => 'unpaid',
                },
            ];
        }

        $assignedWaiterView = null;

        if ($waiterId !== null) {
            $assignedWaiterView = [
                'id' => $waiterId,
                'name' => $waiter?->name,
                'sub_id' => $restaurantUsersByUserId->get($waiterId)?->sub_id,
            ];
        }

        return $base + [
            'primary_status' => $state['primary_status'],
            'flags' => $state['flags'],
            'session' => [
                'id' => $session->id,
                'started_at' => $session->opened_at,
                'elapsed_seconds' => ElapsedTime::seconds($now, $session->opened_at),
                'guest_count' => $session->guest_count,
                'assigned_waiter' => $assignedWaiterView,
            ],
            'orders' => [
                'open_count' => $sessionOpenOrders->count(),
                'waiting_approval' => $sessionOpenOrders->where('status', Order::STATUS_WAITING_APPROVAL)->count(),
                'preparing' => $sessionOpenOrders->whereIn('status', $preparingBucket)->count(),
                'ready' => $sessionOpenOrders->where('status', Order::STATUS_READY)->count(),
            ],
            'billing' => $billingView,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function emptyState(): array
    {
        return [
            'has_active_session' => false,
            'has_bill_requested' => false,
            'has_waiter_requested' => false,
            'has_responsible_waiter_called' => false,
            'has_ready_order' => false,
            'has_waiting_approval_order' => false,
            'has_preparing_order' => false,
            'is_unassigned' => false,
            'assigned_waiter_off_shift' => false,
            'assigned_waiter_suspended' => false,
        ];
    }

    /**
     * Assembles the Floor -> Zone -> Table tree (and the separate
     * unassigned_tables list) from already-built table views — no
     * queries, just grouping already-loaded data.
     *
     * @param  array<int, array<string, mixed>>  $tableViews
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function buildFloorTree(Collection $floors, Collection $zones, array $tableViews): array
    {
        $tableViewsByZoneId = [];
        $unassignedTableViews = [];

        foreach ($tableViews as $view) {
            if ($view['zone_id'] === null) {
                $unassignedTableViews[] = $view;
            } else {
                $tableViewsByZoneId[$view['zone_id']][] = $view;
            }
        }

        $zonesByFloorId = [];
        foreach ($zones as $zone) {
            $zonesByFloorId[$zone->floor_id][] = $zone;
        }

        $floorViews = [];
        foreach ($floors as $floor) {
            $zoneViews = [];
            foreach ($zonesByFloorId[$floor->id] ?? [] as $zone) {
                $zoneViews[] = [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'sort_order' => $zone->sort_order,
                    'is_active' => $zone->is_active,
                    'tables' => $tableViewsByZoneId[$zone->id] ?? [],
                ];
            }

            $floorViews[] = [
                'id' => $floor->id,
                'name' => $floor->name,
                'sort_order' => $floor->sort_order,
                'is_active' => $floor->is_active,
                'zones' => $zoneViews,
            ];
        }

        return [$floorViews, $unassignedTableViews];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildStaffViews(
        Collection $activeShifts,
        Collection $usersById,
        Collection $rolesByUserId,
        Collection $activeSessions,
        Collection $pendingTableRequestsBySession,
        Collection $pendingWaiterCallsBySession,
        CarbonImmutable $now,
    ): array {
        $views = [];

        foreach ($activeShifts as $shift) {
            $user = $usersById->get($shift->user_id);
            $waiterSessions = $activeSessions->filter(
                fn ($session) => $session->assigned_waiter_user_id === $shift->user_id
            );

            $pendingAttention = 0;
            foreach ($waiterSessions as $session) {
                $pendingAttention += $pendingTableRequestsBySession->get($session->id, collect())->count();
                $pendingAttention += $pendingWaiterCallsBySession->get($session->id, collect())->count();
            }

            $views[] = [
                'user' => ['id' => $shift->user_id, 'name' => $user?->name],
                'role' => $rolesByUserId->get($shift->user_id)?->role?->slug,
                'shift_started_at' => $shift->started_at,
                'active_seconds' => ElapsedTime::seconds($now, $shift->started_at),
                'load' => [
                    'assigned_tables' => $waiterSessions->count(),
                    'assigned_guests' => (int) $waiterSessions->sum('guest_count'),
                    'pending_attention' => $pendingAttention,
                ],
            ];
        }

        return $views;
    }
}

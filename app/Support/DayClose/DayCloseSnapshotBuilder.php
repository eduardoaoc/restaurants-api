<?php

namespace App\Support\DayClose;

use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\Restaurant;
use App\Models\RestaurantCashMovement;
use App\Models\RestaurantProduct;
use App\Models\StaffShift;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Support\Activity\RestaurantActivityType;
use App\Support\Analytics\ProductAnalytics;
use App\Support\Billing\PaymentsSummary;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Computes every live section of a Cierre Diario over [start, end)
 * (CARTA 9.1A). Used by both the preview (no lock) and the close (under
 * the EXCLUSIVE RestaurantOperationalLock, so every section reads the
 * same committed state).
 *
 * Query budget is constant: each section is one or two aggregate/list
 * queries regardless of how many orders, products or reviews the period
 * holds — never a query per row.
 *
 * Every returned value is a string/int/bool/null (see DayCloseFormat).
 */
class DayCloseSnapshotBuilder
{
    public const TOP_PRODUCTS_LIMIT = 5;

    public const DELAY_DETAILS_LIMIT = 20;

    public const ATTENTION_FEEDBACK_LIMIT = 20;

    public const DELAY_STAGE_ACCEPT = 'accept';

    public const DELAY_STAGE_PREPARATION = 'preparation';

    public const DELAY_STAGE_READY_PICKUP = 'ready_pickup';

    /**
     * Money actually received (PaymentRecord), never Order totals. Totals
     * and sessions_with_payments/average_ticket reuse PaymentsSummary —
     * the one shared definition — plus a per-method breakdown.
     *
     * @return array{total_received_cents: int, by_method_cents: array<string, int>, payments_count: int, sessions_with_payments: int, average_ticket_cents: int}
     */
    public function financial(Restaurant $restaurant, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $totalCents = PaymentsSummary::totalCents($restaurant->id, $start, $end);
        $sessionsWithPayments = PaymentsSummary::sessionsWithPaymentsCount($restaurant->id, $start, $end);

        $rows = PaymentRecord::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('recorded_at', '>=', $start)
            ->where('recorded_at', '<', $end)
            ->select('method')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as payments_count')
            ->groupBy('method')
            ->get()
            ->keyBy('method');

        $byMethod = [];
        $count = 0;

        foreach (PaymentRecord::METHODS as $method) {
            $row = $rows->get($method);
            $byMethod[$method] = $row ? Money::decimalToCents((string) $row->total_amount) : 0;
            $count += $row ? (int) $row->payments_count : 0;
        }

        return [
            'total_received_cents' => $totalCents,
            'by_method_cents' => $byMethod,
            'payments_count' => $count,
            'sessions_with_payments' => $sessionsWithPayments,
            'average_ticket_cents' => PaymentsSummary::averageTicketCents($totalCents, $sessionsWithPayments),
        ];
    }

    /**
     * @return array{pay_ins_cents: int, pay_outs_cents: int, movements: array<int, array<string, mixed>>}
     */
    public function cashMovements(Restaurant $restaurant, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $movements = RestaurantCashMovement::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('recorded_at', '>=', $start)
            ->where('recorded_at', '<', $end)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['id', 'type', 'amount', 'reason', 'recorded_by_user_id', 'recorded_by_name_snapshot', 'recorded_at']);

        $sum = fn (string $type) => $movements->where('type', $type)
            ->sum(fn (RestaurantCashMovement $movement) => Money::decimalToCents((string) $movement->amount));

        return [
            'pay_ins_cents' => $sum(RestaurantCashMovement::TYPE_PAY_IN),
            'pay_outs_cents' => $sum(RestaurantCashMovement::TYPE_PAY_OUT),
            'movements' => $movements->map(fn (RestaurantCashMovement $movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'amount' => (string) $movement->amount,
                'reason' => $movement->reason,
                'recorded_by' => ['id' => $movement->recorded_by_user_id, 'name' => $movement->recorded_by_name_snapshot],
                'recorded_at' => DayCloseFormat::instant($movement->recorded_at),
            ])->values()->all(),
        ];
    }

    /**
     * Order and session counts with explicit definitions:
     *   orders.registered: created in the period, any status;
     *   orders.valid: created in the period AND billable (not
     *     waiting_approval, not rejected);
     *   orders.served: served_at in the period;
     *   orders.rejected: rejected (the only cancellation that exists) in
     *     the period;
     *   sessions.opened/closed/guests: real service only — voided
     *     sessions are excluded and reported apart;
     *   peak_hour: the local calendar-hour bucket (date + hour, so two
     *     days' 21:00 never merge) with the most sessions opened; ties go
     *     to the earliest bucket. In the autumn DST change the repeated
     *     local hour shares one bucket.
     *
     * @return array<string, mixed>
     */
    public function operations(Restaurant $restaurant, CarbonImmutable $start, CarbonImmutable $end, string $timezone): array
    {
        $billable = Order::billableStatuses();
        $inList = implode(', ', array_fill(0, count($billable), '?'));
        $range = fn (string $column) => "{$column} >= ? AND {$column} < ?";

        $orders = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('created_at', '>=', $start)->where('created_at', '<', $end))
                ->orWhere(fn ($q) => $q->where('served_at', '>=', $start)->where('served_at', '<', $end))
                // status first: (restaurant_id, status, created_at) is the
                // only index that can serve this branch (cancelled_at has
                // none), keeping a BitmapOr possible instead of a scan.
                ->orWhere(fn ($q) => $q->where('status', Order::STATUS_CANCELLED)->where('cancelled_at', '>=', $start)->where('cancelled_at', '<', $end)))
            ->selectRaw(
                'COUNT(*) FILTER (WHERE '.$range('created_at').') AS registered, '.
                'COUNT(*) FILTER (WHERE '.$range('created_at')." AND status IN ({$inList})) AS valid, ".
                'COUNT(*) FILTER (WHERE '.$range('served_at').') AS served, '.
                'COUNT(*) FILTER (WHERE status = ? AND '.$range('cancelled_at').') AS rejected',
                [$start, $end, $start, $end, ...$billable, $start, $end, Order::STATUS_CANCELLED, $start, $end],
            )
            ->first();

        $sessions = TableSession::query()
            ->where('restaurant_id', $restaurant->id)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('opened_at', '>=', $start)->where('opened_at', '<', $end))
                ->orWhere(fn ($q) => $q->where('closed_at', '>=', $start)->where('closed_at', '<', $end)))
            ->selectRaw(
                'COUNT(*) FILTER (WHERE voided_at IS NULL AND '.$range('opened_at').') AS opened, '.
                "COUNT(*) FILTER (WHERE status = 'closed' AND voided_at IS NULL AND ".$range('closed_at').') AS closed, '.
                "COALESCE(SUM(guest_count) FILTER (WHERE status = 'closed' AND voided_at IS NULL AND ".$range('closed_at').'), 0) AS guests, '.
                'COUNT(*) FILTER (WHERE voided_at IS NOT NULL AND '.$range('voided_at').') AS voided',
                [$start, $end, $start, $end, $start, $end, $start, $end],
            )
            ->first();

        $peak = TableSession::query()
            ->where('restaurant_id', $restaurant->id)
            ->notVoided()
            ->where('opened_at', '>=', $start)
            ->where('opened_at', '<', $end)
            ->selectRaw(
                "to_char(date_trunc('hour', opened_at AT TIME ZONE 'UTC' AT TIME ZONE ?), 'YYYY-MM-DD\"T\"HH24:00') AS local_hour, COUNT(*) AS sessions_started",
                [$timezone],
            )
            ->groupBy('local_hour')
            ->orderByDesc('sessions_started')
            ->orderBy('local_hour')
            ->first();

        return [
            'orders' => [
                'registered' => (int) $orders->registered,
                'valid' => (int) $orders->valid,
                'served' => (int) $orders->served,
                'rejected' => (int) $orders->rejected,
            ],
            'sessions' => [
                'opened' => (int) $sessions->opened,
                'closed' => (int) $sessions->closed,
                'voided' => (int) $sessions->voided,
            ],
            'guests' => (int) $sessions->guests,
            'peak_hour' => $peak ? ['local_hour' => $peak->local_hour, 'sessions_started' => (int) $peak->sessions_started] : null,
        ];
    }

    /**
     * Top 5 by quantity — ProductAnalytics::topByQuantity is exactly the
     * right definition (billable orders created in the period, grouped by
     * the OrderItem name snapshot, deterministic tie-break).
     *
     * @return array<int, array{product_id: ?int, name: string, quantity: int}>
     */
    public function topProducts(Restaurant $restaurant, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return ProductAnalytics::topByQuantity($restaurant, $start, $end, self::TOP_PRODUCTS_LIMIT);
    }

    /**
     * "Producto marcado como no disponible" timeline, rebuilt from the
     * product.marked_unavailable/available activity events (CARTA 6.1A)
     * plus the products unavailable at the end of the period. Never
     * "stock": AFORO has no quantities.
     *
     * Per product, the state at the start of the period comes from its
     * last event before the start; without one, it is inferred from its
     * first event inside the period (a first "available" means it was
     * unavailable). An interval that started before any recorded event is
     * reported with unavailable_since_known = false ("inicio
     * desconocido") — never as "the whole period". Consecutive events of
     * the same type are collapsed.
     *
     * @return array{count: int, items: array<int, array<string, mixed>>}
     */
    public function productAvailability(Restaurant $restaurant, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $types = [RestaurantActivityType::PRODUCT_MARKED_UNAVAILABLE, RestaurantActivityType::PRODUCT_MARKED_AVAILABLE];
        $productKey = "(metadata->>'restaurant_product_id')";

        $inPeriod = DB::table('restaurant_activity_events')
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('type', $types)
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'type', 'occurred_at', 'actor_name_snapshot', DB::raw("{$productKey}::bigint AS restaurant_product_id"), DB::raw("metadata->>'product_name' AS product_name")])
            ->groupBy('restaurant_product_id');

        $unavailableAtEnd = RestaurantProduct::query()
            ->join('products', 'products.id', '=', 'restaurant_products.product_id')
            ->where('restaurant_products.restaurant_id', $restaurant->id)
            ->where('restaurant_products.available', false)
            ->pluck('products.internal_name', 'restaurant_products.id');

        $productIds = collect($inPeriod->keys())->map(fn ($id) => (int) $id)
            ->merge($unavailableAtEnd->keys()->map(fn ($id) => (int) $id))
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return ['count' => 0, 'items' => []];
        }

        $before = DB::table('restaurant_activity_events')
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('type', $types)
            ->where('occurred_at', '<', $start)
            ->whereIn(DB::raw("{$productKey}::bigint"), $productIds->all())
            ->orderByRaw("{$productKey}, occurred_at DESC, id DESC")
            ->selectRaw("DISTINCT ON ({$productKey}) {$productKey}::bigint AS restaurant_product_id, type, occurred_at, actor_name_snapshot, metadata->>'product_name' AS product_name")
            ->get()
            ->keyBy('restaurant_product_id');

        $items = [];

        foreach ($productIds as $productId) {
            $events = $inPeriod->get($productId, collect())->values();
            $prior = $before->get($productId);
            $name = $events->last()->product_name ?? $prior->product_name ?? $unavailableAtEnd->get($productId);

            if ($prior !== null) {
                $unavailable = $prior->type === RestaurantActivityType::PRODUCT_MARKED_UNAVAILABLE;
                $open = $unavailable ? ['at' => $prior->occurred_at, 'by' => $prior->actor_name_snapshot, 'known' => true] : null;
            } elseif ($events->isNotEmpty()) {
                $unavailable = $events->first()->type === RestaurantActivityType::PRODUCT_MARKED_AVAILABLE;
                $open = $unavailable ? ['at' => null, 'by' => null, 'known' => false] : null;
            } else {
                // No event at all: only here because it is unavailable now.
                $unavailable = true;
                $open = ['at' => null, 'by' => null, 'known' => false];
            }

            foreach ($events as $event) {
                if ($event->type === RestaurantActivityType::PRODUCT_MARKED_UNAVAILABLE && ! $unavailable) {
                    $unavailable = true;
                    $open = ['at' => $event->occurred_at, 'by' => $event->actor_name_snapshot, 'known' => true];
                } elseif ($event->type === RestaurantActivityType::PRODUCT_MARKED_AVAILABLE && $unavailable) {
                    $items[] = $this->availabilityItem($productId, $name, $open, $event->occurred_at, $event->actor_name_snapshot);
                    $unavailable = false;
                    $open = null;
                }
            }

            if ($unavailable) {
                $items[] = $this->availabilityItem($productId, $name, $open, null, null);
            }
        }

        usort($items, fn (array $a, array $b) => [$a['unavailable_at'] ?? '', $a['restaurant_product_id']] <=> [$b['unavailable_at'] ?? '', $b['restaurant_product_id']]);

        return [
            'count' => collect($items)->pluck('restaurant_product_id')->unique()->count(),
            'items' => $items,
        ];
    }

    /**
     * @param  array{at: ?string, by: ?string, known: bool}  $open
     * @return array<string, mixed>
     */
    private function availabilityItem(int $productId, ?string $name, array $open, ?string $availableAt, ?string $availableBy): array
    {
        $unavailableAt = $open['at'] !== null ? CarbonImmutable::parse($open['at'], 'UTC') : null;
        $availableAgainAt = $availableAt !== null ? CarbonImmutable::parse($availableAt, 'UTC') : null;

        return [
            'restaurant_product_id' => $productId,
            'product_name_snapshot' => $name,
            'unavailable_since_known' => $open['known'],
            'unavailable_at' => DayCloseFormat::instant($unavailableAt),
            'unavailable_by_name' => $open['by'],
            'available_again_at' => DayCloseFormat::instant($availableAgainAt),
            'available_again_by_name' => $availableBy,
            'duration_seconds' => $unavailableAt !== null && $availableAgainAt !== null
                ? (int) $availableAgainAt->getTimestamp() - $unavailableAt->getTimestamp()
                : null,
            'state_at_close' => $availableAgainAt === null ? 'unavailable' : 'available',
        ];
    }

    /**
     * Feedback by submitted_at in the period (late feedback belongs to the
     * close of the day it arrives). critical = overall_rating < 3;
     * low_dimension ("atención") = overall >= 3 with some food/service/
     * wait_time rating <= 2 — reported, never counted as critical. The
     * average is the real overall_rating mean, nothing derived. Customer
     * PII (first/last name, contact) is never selected.
     *
     * @return array<string, mixed>
     */
    public function feedback(Restaurant $restaurant, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $lowDimension = 'overall_rating >= 3 AND LEAST(food_rating, service_rating, wait_time_rating) <= 2';

        $summary = DB::table('customer_feedbacks')
            ->where('restaurant_id', $restaurant->id)
            ->where('submitted_at', '>=', $start)
            ->where('submitted_at', '<', $end)
            ->selectRaw(
                'COUNT(*) AS feedback_count, ROUND(AVG(overall_rating)::numeric, 2)::text AS avg_overall, '.
                "COUNT(*) FILTER (WHERE overall_rating < 3) AS critical_count, COUNT(*) FILTER (WHERE {$lowDimension}) AS low_dimension_count"
            )
            ->first();

        $detail = fn (string $condition, ?int $limit) => DB::table('customer_feedbacks as cf')
            ->leftJoin('table_sessions as ts', 'ts.id', '=', 'cf.table_session_id')
            ->leftJoin('tables as t', 't.id', '=', 'ts.table_id')
            ->leftJoin('users as w', 'w.id', '=', 'cf.waiter_id')
            ->where('cf.restaurant_id', $restaurant->id)
            ->where('cf.submitted_at', '>=', $start)
            ->where('cf.submitted_at', '<', $end)
            ->whereRaw($condition)
            ->orderBy('cf.submitted_at')
            ->orderBy('cf.id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get([
                'cf.id', 'cf.submitted_at', 'cf.table_session_id', 't.id as table_id', 't.name as table_name', 't.number as table_number',
                'cf.overall_rating', 'cf.food_rating', 'cf.service_rating', 'cf.wait_time_rating',
                'cf.experience_comment', 'cf.improvement_comment', 'w.name as waiter_name',
            ])
            ->map(fn ($row) => [
                'feedback_id' => (int) $row->id,
                'submitted_at' => DayCloseFormat::instant(CarbonImmutable::parse($row->submitted_at, 'UTC')),
                'table' => $row->table_id !== null ? ['id' => (int) $row->table_id, 'name' => $row->table_name, 'number' => $row->table_number !== null ? (int) $row->table_number : null] : null,
                'table_session_id' => (int) $row->table_session_id,
                'ratings' => [
                    'overall' => (int) $row->overall_rating,
                    'food' => (int) $row->food_rating,
                    'service' => (int) $row->service_rating,
                    'wait_time' => (int) $row->wait_time_rating,
                ],
                'experience_comment' => $row->experience_comment,
                'improvement_comment' => $row->improvement_comment,
                'waiter_name' => $row->waiter_name,
            ])->values()->all();

        $count = (int) $summary->feedback_count;

        return [
            'count' => $count,
            'avg_overall' => $count > 0 ? $summary->avg_overall : null,
            'critical_count' => (int) $summary->critical_count,
            'low_dimension_count' => (int) $summary->low_dimension_count,
            'critical' => (int) $summary->critical_count > 0 ? $detail('cf.overall_rating < 3', null) : [],
            'attention' => (int) $summary->low_dimension_count > 0
                ? $detail(str_replace(['overall_rating', 'food_rating', 'service_rating', 'wait_time_rating'], ['cf.overall_rating', 'cf.food_rating', 'cf.service_rating', 'cf.wait_time_rating'], $lowDimension), self::ATTENTION_FEEDBACK_LIMIT)
                : [],
        ];
    }

    /**
     * Severe delays per stage, attributed to the period by the instant the
     * stage FINISHED (an unfinished stage has no final duration — an order
     * still in progress shows up as a blocker through its active session):
     *   accept:       COALESCE(approved_at, created_at) -> accepted_at, no person;
     *   preparation:  preparing_at -> ready_at, "marcado listo por" ready_by;
     *   ready_pickup: ready_at -> served_at, "servido por" served_by.
     * The person is the one who performed the closing action, never "the
     * one responsible". A delay = duration strictly above the threshold.
     * One query: the 20 worst by excess plus the full count (window
     * function computed before LIMIT).
     *
     * @return array<string, mixed>
     */
    public function delays(Restaurant $restaurant, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $settings = $restaurant->settings;
        $thresholds = [
            self::DELAY_STAGE_ACCEPT => $settings->accept_delay_threshold_minutes * 60,
            self::DELAY_STAGE_PREPARATION => $settings->preparation_delay_threshold_minutes * 60,
            self::DELAY_STAGE_READY_PICKUP => $settings->ready_pickup_delay_threshold_minutes * 60,
        ];

        $stage = fn (string $name, string $from, string $to, ?string $userColumn) => "SELECT o.id AS order_id, '{$name}' AS stage, o.table_id, "
            ."EXTRACT(EPOCH FROM (o.{$to} - {$from}))::bigint AS duration_seconds, ?::bigint AS threshold_seconds, "
            .($userColumn !== null ? "o.{$userColumn}" : 'NULL::bigint').' AS user_id '
            ."FROM orders o WHERE o.restaurant_id = ? AND o.{$to} >= ? AND o.{$to} < ?";

        $union = implode(' UNION ALL ', [
            $stage(self::DELAY_STAGE_ACCEPT, 'COALESCE(o.approved_at, o.created_at)', 'accepted_at', null),
            $stage(self::DELAY_STAGE_PREPARATION, 'o.preparing_at', 'ready_at', 'ready_by_user_id').' AND o.preparing_at IS NOT NULL',
            $stage(self::DELAY_STAGE_READY_PICKUP, 'o.ready_at', 'served_at', 'served_by_user_id').' AND o.ready_at IS NOT NULL',
        ]);

        $bindings = [];
        foreach ($thresholds as $threshold) {
            array_push($bindings, $threshold, $restaurant->id, $start, $end);
        }

        $rows = DB::select(
            'SELECT x.*, t.name AS table_name, t.number AS table_number, u.name AS user_name FROM ('
            ."SELECT d.*, COUNT(*) OVER () AS total_count FROM ({$union}) d "
            .'WHERE d.duration_seconds > d.threshold_seconds '
            .'ORDER BY (d.duration_seconds - d.threshold_seconds) DESC, d.order_id ASC, d.stage ASC LIMIT '.self::DELAY_DETAILS_LIMIT
            .') x LEFT JOIN tables t ON t.id = x.table_id LEFT JOIN users u ON u.id = x.user_id '
            .'ORDER BY (x.duration_seconds - x.threshold_seconds) DESC, x.order_id ASC, x.stage ASC',
            $bindings,
        );

        $labels = [
            self::DELAY_STAGE_ACCEPT => null,
            self::DELAY_STAGE_PREPARATION => 'marked_ready_by',
            self::DELAY_STAGE_READY_PICKUP => 'served_by',
        ];

        return [
            'thresholds_seconds' => $thresholds,
            'total_count' => $rows === [] ? 0 : (int) $rows[0]->total_count,
            'items' => array_map(fn ($row) => [
                'order_id' => (int) $row->order_id,
                'order_reference' => '#'.$row->order_id,
                'table' => $row->table_id !== null ? ['id' => (int) $row->table_id, 'name' => $row->table_name, 'number' => $row->table_number !== null ? (int) $row->table_number : null] : null,
                'stage' => $row->stage,
                'duration_seconds' => (int) $row->duration_seconds,
                'threshold_seconds' => (int) $row->threshold_seconds,
                'excess_seconds' => (int) $row->duration_seconds - (int) $row->threshold_seconds,
                'associated_action_user' => $row->user_id !== null ? ['id' => (int) $row->user_id, 'name' => $row->user_name] : null,
                'associated_action_label' => $row->user_id !== null ? $labels[$row->stage] : null,
            ], $rows),
        ];
    }

    /**
     * Blockers: EVERY active table session (no force close in this
     * version). Each one carries the diagnostics a UI needs to resolve it,
     * including whether it is empty enough to be voided.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeSessionBlockers(Restaurant $restaurant): array
    {
        $sessions = TableSession::query()
            ->where('table_sessions.restaurant_id', $restaurant->id)
            ->where('table_sessions.status', '!=', 'closed')
            ->join('tables', 'tables.id', '=', 'table_sessions.table_id')
            ->orderBy('table_sessions.opened_at')
            ->orderBy('table_sessions.id')
            ->get(['table_sessions.id', 'table_sessions.table_id', 'table_sessions.opened_at', 'table_sessions.payment_status', 'tables.name as table_name', 'tables.number as table_number']);

        if ($sessions->isEmpty()) {
            return [];
        }

        $ids = $sessions->pluck('id')->all();
        $bills = SessionBillCalculator::summarizeMany($ids);
        $openOrders = Order::query()
            ->whereIn('table_session_id', $ids)
            ->whereIn('status', Order::openStatuses())
            ->selectRaw('table_session_id, COUNT(*) AS open_count')
            ->groupBy('table_session_id')
            ->pluck('open_count', 'table_session_id');

        return $sessions->map(function (TableSession $session) use ($bills, $openOrders) {
            $bill = $bills[$session->id];
            $openCount = (int) ($openOrders[$session->id] ?? 0);

            return [
                'type' => 'active_session',
                'table_session_id' => $session->id,
                'table' => ['id' => $session->table_id, 'name' => $session->table_name, 'number' => $session->table_number],
                'opened_at' => DayCloseFormat::instant($session->opened_at),
                'payment_status' => $session->payment_status,
                'balance' => DayCloseFormat::money($bill['balanceCents']),
                'open_orders_count' => $openCount,
                'can_be_voided' => $openCount === 0 && ! $bill['hasBillableOrders'] && $bill['paidTotalCents'] === 0,
            ];
        })->values()->all();
    }

    /**
     * Non-blocking warnings about the live operation (period-independent
     * state). Report-derived warnings (critical feedback, delays, cash
     * difference, products still unavailable) are added by the caller
     * from the computed sections.
     *
     * @return array<int, array<string, mixed>>
     */
    public function operationalWarnings(Restaurant $restaurant): array
    {
        $warnings = [];

        $openRequests = TableRequest::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', TableRequest::openStatuses())
            ->whereHas('tableSession', fn ($query) => $query->where('status', 'closed'))
            ->count();

        if ($openRequests > 0) {
            $warnings[] = ['type' => 'open_table_requests_without_active_session', 'count' => $openRequests];
        }

        $shifts = StaffShift::query()
            ->where('staff_shifts.restaurant_id', $restaurant->id)
            ->whereNull('staff_shifts.ended_at')
            ->join('users', 'users.id', '=', 'staff_shifts.user_id')
            ->orderBy('staff_shifts.started_at')
            ->get(['staff_shifts.user_id', 'users.name', 'staff_shifts.started_at']);

        if ($shifts->isNotEmpty()) {
            $warnings[] = [
                'type' => 'active_staff_shifts',
                'count' => $shifts->count(),
                'staff' => $shifts->map(fn ($shift) => [
                    'user_id' => $shift->user_id,
                    'name' => $shift->name,
                    'started_at' => DayCloseFormat::instant($shift->started_at),
                ])->values()->all(),
            ];
        }

        return $warnings;
    }
}

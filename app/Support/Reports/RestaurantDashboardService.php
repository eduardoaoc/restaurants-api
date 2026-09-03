<?php

namespace App\Support\Reports;

use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\Restaurant;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Computes the operational dashboard's aggregations for one restaurant and
 * one half-open period. Every number here is a SQL aggregate (COUNT /
 * COUNT DISTINCT / SUM / GROUP BY) against Orders, PaymentRecords,
 * TableSessions and TableRequests — never a collection loaded into PHP to
 * be counted there, and never derived from AuditLog (see report: AuditLog
 * is history/audit, not the source of truth for operational numbers).
 *
 * `restaurant_id` is always in the WHERE clause of every query — the
 * dashboard is always of one explicit Restaurant, never aggregated across
 * an organization, even for an owner who can reach several.
 */
class RestaurantDashboardService
{
    /**
     * @return array{
     *     sales: array{total: string, average_ticket: string, sessions_with_payments: int},
     *     orders: array{created: int, served: int, cancelled: int, customer_qr: int, staff_created: int},
     *     tables: array{sessions_opened: int, sessions_closed: int, current_active: int},
     *     payments: array{total_records: int, by_method: array<string, array{count: int, amount: string}>},
     *     requests: array{call_waiter: int, request_bill: int, completed: int},
     *     staff: array{top_by_orders_served: array<int, array{staff: array{id: int, name: string}, orders_served: int}>},
     * }
     */
    public function summarize(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        return [
            'sales' => $this->sales($restaurant, $from, $toExclusive),
            'orders' => $this->orders($restaurant, $from, $toExclusive),
            'tables' => $this->tables($restaurant, $from, $toExclusive),
            'payments' => $this->payments($restaurant, $from, $toExclusive),
            'requests' => $this->requests($restaurant, $from, $toExclusive),
            'staff' => $this->staff($restaurant, $from, $toExclusive),
        ];
    }

    /**
     * sales.total is SUM(payment_records.amount) filtered by recorded_at —
     * money actually collected, not Order totals (an order can be served
     * and never paid, or paid across several PaymentRecords).
     * sessions_with_payments is the count of distinct sessions with at
     * least one payment in the period — including a partially paid one,
     * not only fully-settled sessions, hence the name (renamed from the
     * more ambiguous "paid_sessions" — see Bloco 18 report). average_ticket
     * divides the two, rounded to the nearest cent, and is "0.00" (never
     * null) when there are no such sessions — it is a period financial
     * metric, not a rating.
     *
     * @return array{total: string, average_ticket: string, sessions_with_payments: int}
     */
    private function sales(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $row = PaymentRecord::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<', $toExclusive)
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount, COUNT(DISTINCT table_session_id) as sessions_with_payments')
            ->first();

        $totalCents = Money::decimalToCents((string) $row->total_amount);
        $sessionsWithPayments = (int) $row->sessions_with_payments;

        return [
            'total' => Money::centsToDecimal($totalCents),
            'average_ticket' => $sessionsWithPayments > 0
                ? Money::centsToDecimal((int) round($totalCents / $sessionsWithPayments))
                : '0.00',
            'sessions_with_payments' => $sessionsWithPayments,
        ];
    }

    /**
     * created/customer_qr/staff_created are all filtered by created_at;
     * served by served_at (an order created in one period can be served in
     * another). cancelled is filtered by cancelled_at: Order::cancelled_at
     * is set exclusively by RejectOrderAction, atomically with
     * status=cancelled, and is the only path that ever produces a
     * cancelled order today — so unlike a generic "last updated_at" it is
     * a fully reliable cancellation timestamp, not a borrowed/ambiguous
     * one. staff_created is origin != customer_qr (the real actor-origin
     * field), not "created_by_user_id is not null".
     *
     * @return array{created: int, served: int, cancelled: int, customer_qr: int, staff_created: int}
     */
    private function orders(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $created = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->selectRaw(
                'COUNT(*) as created, '.
                'COUNT(*) FILTER (WHERE origin = ?) as customer_qr, '.
                'COUNT(*) FILTER (WHERE origin != ?) as staff_created',
                [Order::ORIGIN_CUSTOMER_QR, Order::ORIGIN_CUSTOMER_QR]
            )
            ->first();

        $served = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('served_at', '>=', $from)
            ->where('served_at', '<', $toExclusive)
            ->count();

        $cancelled = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', Order::STATUS_CANCELLED)
            ->where('cancelled_at', '>=', $from)
            ->where('cancelled_at', '<', $toExclusive)
            ->count();

        return [
            'created' => (int) $created->created,
            'served' => $served,
            'cancelled' => $cancelled,
            'customer_qr' => (int) $created->customer_qr,
            'staff_created' => (int) $created->staff_created,
        ];
    }

    /**
     * sessions_opened/sessions_closed respect the period (opened_at/
     * closed_at). current_active does NOT respect the period — it is a
     * snapshot of right now (status != closed), so it can be non-zero even
     * for a period with no other activity, or differ from
     * sessions_opened - sessions_closed for the same period.
     *
     * @return array{sessions_opened: int, sessions_closed: int, current_active: int}
     */
    private function tables(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $opened = TableSession::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('opened_at', '>=', $from)
            ->where('opened_at', '<', $toExclusive)
            ->count();

        $closed = TableSession::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('closed_at', '>=', $from)
            ->where('closed_at', '<', $toExclusive)
            ->count();

        $currentActive = TableSession::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', '!=', 'closed')
            ->count();

        return [
            'sessions_opened' => $opened,
            'sessions_closed' => $closed,
            'current_active' => $currentActive,
        ];
    }

    /**
     * Every method in PaymentRecord::METHODS is always present in
     * by_method, zero-filled when unused in the period — never a sparse
     * map that omits a method with no records.
     *
     * @return array{total_records: int, by_method: array<string, array{count: int, amount: string}>}
     */
    private function payments(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $rows = PaymentRecord::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<', $toExclusive)
            ->select('method')
            ->selectRaw('COUNT(*) as record_count, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('method')
            ->get()
            ->keyBy('method');

        $byMethod = [];
        $totalRecords = 0;

        foreach (PaymentRecord::METHODS as $method) {
            $row = $rows->get($method);
            $count = $row ? (int) $row->record_count : 0;
            $totalRecords += $count;

            $byMethod[$method] = [
                'count' => $count,
                'amount' => Money::centsToDecimal(Money::decimalToCents((string) ($row->total_amount ?? '0'))),
            ];
        }

        return [
            'total_records' => $totalRecords,
            'by_method' => $byMethod,
        ];
    }

    /**
     * call_waiter/request_bill are counted by created_at (every request
     * created in the period, regardless of outcome). completed is
     * status=completed filtered by completed_at — a request acknowledged
     * but not completed does not count. Response-time metrics
     * (pending->acknowledged->completed durations) are deliberately out of
     * scope for this block — see report.
     *
     * @return array{call_waiter: int, request_bill: int, completed: int}
     */
    private function requests(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $created = TableRequest::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->selectRaw(
                'COUNT(*) FILTER (WHERE type = ?) as call_waiter, '.
                'COUNT(*) FILTER (WHERE type = ?) as request_bill',
                [TableRequest::TYPE_CALL_WAITER, TableRequest::TYPE_REQUEST_BILL]
            )
            ->first();

        $completed = TableRequest::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', TableRequest::STATUS_COMPLETED)
            ->where('completed_at', '>=', $from)
            ->where('completed_at', '<', $toExclusive)
            ->count();

        return [
            'call_waiter' => (int) $created->call_waiter,
            'request_bill' => (int) $created->request_bill,
            'completed' => $completed,
        ];
    }

    /**
     * Purely factual ordering by orders_served — never called a "score" or
     * "ranking" in the domain sense (see StaffPerformance for the actual
     * rating). Ties break on staff_user_id ascending for a deterministic
     * order. Capped at 5; a staff member with zero served orders in the
     * period never appears.
     *
     * @return array{top_by_orders_served: array<int, array{staff: array{id: int, name: string}, orders_served: int}>}
     */
    private function staff(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $rows = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereNotNull('served_by_user_id')
            ->where('served_at', '>=', $from)
            ->where('served_at', '<', $toExclusive)
            ->select('served_by_user_id')
            ->selectRaw('COUNT(*) as orders_served')
            ->groupBy('served_by_user_id')
            ->orderByDesc('orders_served')
            ->orderBy('served_by_user_id')
            ->limit(5)
            ->get();

        $staffUsers = User::query()
            ->whereIn('id', $rows->pluck('served_by_user_id'))
            ->get()
            ->keyBy('id');

        $top = $rows->map(function ($row) use ($staffUsers) {
            $user = $staffUsers->get($row->served_by_user_id);

            return [
                'staff' => [
                    'id' => $row->served_by_user_id,
                    'name' => $user?->name ?? '',
                ],
                'orders_served' => (int) $row->orders_served,
            ];
        })->values()->all();

        return [
            'top_by_orders_served' => $top,
        ];
    }
}

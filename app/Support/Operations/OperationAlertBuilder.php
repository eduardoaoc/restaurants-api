<?php

namespace App\Support\Operations;

use App\Models\Order;
use App\Models\TableRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the normalized, deduplicated list of operational alerts for a
 * Restaurant's live snapshot (Bloco 5). Each alert is derived from exactly
 * one underlying fact, iterated exactly once — never re-discovered by
 * multiple queries — so the same fact can never surface as two alerts.
 * `id` is deterministic (type + the underlying row's own id), so a
 * frontend/future-Reverb consumer can diff snapshots by id.
 *
 * severity: assigned_waiter_suspended -> critical; everything else here ->
 * warning, except order_ready -> info. age_seconds is always "now minus
 * the best available anchor timestamp" — for the two assignment-integrity
 * alerts that has no exact "since when" moment (neither is a real column),
 * so the session's own opened_at is used as the best available signal,
 * not a precise inconsistency-start time — documented here since it's a
 * deliberate approximation, not an oversight.
 */
class OperationAlertBuilder
{
    /**
     * @param  Collection<int, object{id:int,table_id:int,opened_at:CarbonImmutable,assigned_waiter_user_id:?int}>  $activeSessions
     * @param  Collection<int, User>  $usersById
     * @param  array<int, int>  $activeShiftUserIds
     * @param  Collection<int, object{id:int,table_id:int,table_session_id:int,type:string,created_at:CarbonImmutable}>  $pendingTableRequests
     * @param  Collection<int, object{id:int,table_session_id:int,waiter_user_id:int,created_at:CarbonImmutable}>  $pendingWaiterCalls
     * @param  Collection<int, object{id:int,table_id:int,table_session_id:int,status:string,created_at:CarbonImmutable}>  $openOrders
     * @return array<int, array{id:string,type:string,severity:string,table_id:?int,table_session_id:?int,user_id:?int,age_seconds:int}>
     */
    public static function build(
        Collection $activeSessions,
        Collection $usersById,
        array $activeShiftUserIds,
        Collection $pendingTableRequests,
        Collection $pendingWaiterCalls,
        Collection $openOrders,
        CarbonImmutable $now,
    ): array {
        $alerts = [];
        $sessionIdToTableId = $activeSessions->pluck('table_id', 'id');

        foreach ($activeSessions as $session) {
            $waiterId = $session->assigned_waiter_user_id;
            $ageSeconds = ElapsedTime::seconds($now, $session->opened_at);

            if ($waiterId === null) {
                $alerts[] = self::alert("unassigned-{$session->id}", 'active_table_unassigned', 'warning', $session->table_id, $session->id, null, $ageSeconds);

                continue;
            }

            $waiter = $usersById->get($waiterId);

            if ($waiter && $waiter->isSuspended()) {
                $alerts[] = self::alert("waiter-suspended-{$session->id}", 'assigned_waiter_suspended', 'critical', $session->table_id, $session->id, $waiterId, $ageSeconds);
            } elseif (! in_array($waiterId, $activeShiftUserIds, true)) {
                $alerts[] = self::alert("waiter-off-shift-{$session->id}", 'assigned_waiter_off_shift', 'warning', $session->table_id, $session->id, $waiterId, $ageSeconds);
            }
        }

        foreach ($pendingTableRequests as $request) {
            $type = $request->type === TableRequest::TYPE_REQUEST_BILL
                ? 'bill_request_pending'
                : 'customer_waiter_request_pending';

            $alerts[] = self::alert(
                "table-request-{$request->id}",
                $type,
                'warning',
                $request->table_id,
                $request->table_session_id,
                null,
                ElapsedTime::seconds($now, $request->created_at),
            );
        }

        foreach ($pendingWaiterCalls as $call) {
            $alerts[] = self::alert(
                "waiter-call-{$call->id}",
                'responsible_waiter_call_pending',
                'warning',
                $sessionIdToTableId->get($call->table_session_id),
                $call->table_session_id,
                $call->waiter_user_id,
                ElapsedTime::seconds($now, $call->created_at),
            );
        }

        foreach ($openOrders as $order) {
            if ($order->status === Order::STATUS_WAITING_APPROVAL) {
                $alerts[] = self::alert(
                    "order-waiting-approval-{$order->id}",
                    'order_waiting_approval',
                    'warning',
                    $order->table_id,
                    $order->table_session_id,
                    null,
                    ElapsedTime::seconds($now, $order->created_at),
                );
            } elseif ($order->status === Order::STATUS_READY) {
                $alerts[] = self::alert(
                    "order-ready-{$order->id}",
                    'order_ready',
                    'info',
                    $order->table_id,
                    $order->table_session_id,
                    null,
                    ElapsedTime::seconds($now, $order->created_at),
                );
            }
        }

        return $alerts;
    }

    /**
     * @return array{id:string,type:string,severity:string,table_id:?int,table_session_id:?int,user_id:?int,age_seconds:int}
     */
    private static function alert(
        string $id,
        string $type,
        string $severity,
        ?int $tableId,
        ?int $tableSessionId,
        ?int $userId,
        int $ageSeconds,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'severity' => $severity,
            'table_id' => $tableId,
            'table_session_id' => $tableSessionId,
            'user_id' => $userId,
            'age_seconds' => $ageSeconds,
        ];
    }
}

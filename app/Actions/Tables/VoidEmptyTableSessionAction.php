<?php

namespace App\Actions\Tables;

use App\Events\Realtime\TableSessionClosed;
use App\Exceptions\Billing\TableSessionClosedException;
use App\Exceptions\Tables\TableSessionNotEmptyException;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Activity\ActivityActor;
use App\Support\Activity\RestaurantActivityRecorder;
use App\Support\Activity\RestaurantActivityType;
use App\Support\Audit\AuditLogger;
use App\Support\Restaurants\RestaurantOperationalLock;
use Illuminate\Support\Facades\DB;

/**
 * Ends an EMPTY table session without service (CARTA 9.1A) — the explicit
 * answer to "opened by mistake / guests left before ordering". Before this
 * there was no way out: CloseTableAction (correctly) refuses a session
 * without billable orders, so such a session stayed active forever and
 * would block every Cierre Diario.
 *
 * Empty = zero payments, zero billable orders and zero orders still in
 * progress; rejected orders are allowed (they never happened financially).
 * Every precondition is rechecked under the operational shared lock and
 * the session row lock (same ordering rule as every writer — see
 * RestaurantOperationalLock).
 *
 * The session is never deleted: status becomes 'closed' (so it is no
 * longer active and the table is free), closed_at = voided_at, and
 * voided_at/voided_by_user_id/void_reason mark it as NOT an attended
 * session — TableSession::notVoided() keeps it out of every service
 * metric. closed_by_user_id stays null: nobody settled it. Still-open
 * table requests are cancelled exactly like CloseTableAction does, each
 * audited with reason table_session_voided.
 */
class VoidEmptyTableSessionAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RestaurantActivityRecorder $activityRecorder,
    ) {}

    public function execute(TableSession $session, User $voidedBy, ?string $reason): TableSession
    {
        return DB::transaction(function () use ($session, $voidedBy, $reason) {
            RestaurantOperationalLock::shared($session->restaurant_id);

            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActive()) {
                throw new TableSessionClosedException;
            }

            if ($locked->paymentRecords()->exists()) {
                throw new TableSessionNotEmptyException(TableSessionNotEmptyException::REASON_HAS_PAYMENTS);
            }

            $statuses = $locked->orders()->distinct()->pluck('status');

            if ($statuses->intersect(Order::openStatuses())->isNotEmpty()) {
                throw new TableSessionNotEmptyException(TableSessionNotEmptyException::REASON_HAS_OPEN_ORDERS);
            }

            if ($statuses->intersect(Order::billableStatuses())->isNotEmpty()) {
                throw new TableSessionNotEmptyException(TableSessionNotEmptyException::REASON_HAS_BILLABLE_ORDERS);
            }

            $now = now();

            $locked->update([
                'status' => 'closed',
                'closed_at' => $now,
                'voided_at' => $now,
                'voided_by_user_id' => $voidedBy->id,
                'void_reason' => $reason,
            ]);

            $organizationId = $locked->restaurant->organization_id;

            $this->auditLogger->log(
                organizationId: $organizationId,
                restaurantId: $locked->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $voidedBy,
                event: AuditLog::EVENT_TABLE_SESSION_VOIDED,
                resourceType: AuditLog::RESOURCE_TABLE_SESSION,
                resourceId: $locked->id,
                metadata: [
                    'table_id' => $locked->table_id,
                    'guest_count' => $locked->guest_count,
                    'void_reason' => $reason,
                ],
            );

            $this->activityRecorder->record(
                restaurantId: $locked->restaurant_id,
                type: RestaurantActivityType::TABLE_SESSION_VOIDED,
                actor: ActivityActor::staff($voidedBy),
                table: $locked->table,
                tableSessionId: $locked->id,
                metadata: ['void_reason' => $reason],
                occurredAt: $now,
            );

            // Same wire event as a normal close: for every realtime client
            // the table simply became free.
            TableSessionClosed::dispatch($locked->restaurant_id, $locked->table_id, $locked->id, $locked->closed_at);

            TableRequest::query()
                ->where('table_session_id', $locked->id)
                ->whereIn('status', TableRequest::openStatuses())
                ->get()
                ->each(function (TableRequest $tableRequest) use ($voidedBy, $organizationId, $now) {
                    $previousStatus = $tableRequest->status;

                    $tableRequest->update([
                        'status' => TableRequest::STATUS_CANCELLED,
                        'cancelled_by_user_id' => $voidedBy->id,
                        'cancelled_at' => $now,
                    ]);

                    $this->auditLogger->log(
                        organizationId: $organizationId,
                        restaurantId: $tableRequest->restaurant_id,
                        actorType: AuditLog::ACTOR_USER,
                        actor: $voidedBy,
                        event: AuditLog::EVENT_TABLE_REQUEST_CANCELLED,
                        resourceType: AuditLog::RESOURCE_TABLE_REQUEST,
                        resourceId: $tableRequest->id,
                        metadata: [
                            'previous_status' => $previousStatus,
                            'new_status' => TableRequest::STATUS_CANCELLED,
                            'type' => $tableRequest->type,
                            'reason' => 'table_session_voided',
                        ],
                    );
                });

            return $locked->fresh();
        });
    }
}

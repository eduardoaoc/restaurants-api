<?php

namespace App\Actions\Tables;

use App\Events\Realtime\WaiterCallAcknowledged;
use App\Exceptions\Tables\WaiterCallConflictException;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaiterCall;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Acknowledges a pending WaiterCall — pending -> acknowledged, the only
 * transition this minimal resource supports (see the migration/report).
 * Acknowledging an already-acknowledged call is a 409, not idempotent,
 * consistent with EndStaffShiftAction/CloseTableAction's own repeat-action
 * behavior.
 */
class AcknowledgeWaiterCallAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(WaiterCall $call, User $actor): WaiterCall
    {
        return DB::transaction(function () use ($call, $actor) {
            $locked = WaiterCall::query()->whereKey($call->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isPending()) {
                throw new WaiterCallConflictException('This call has already been acknowledged.');
            }

            $locked->update([
                'status' => WaiterCall::STATUS_ACKNOWLEDGED,
                'acknowledged_by_user_id' => $actor->id,
                'acknowledged_at' => now(),
            ]);

            $this->auditLogger->log(
                organizationId: $locked->restaurant->organization_id,
                restaurantId: $locked->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: AuditLog::EVENT_TABLE_SESSION_RESPONSIBLE_WAITER_CALL_ACKNOWLEDGED,
                resourceType: AuditLog::RESOURCE_WAITER_CALL,
                resourceId: $locked->id,
                metadata: [
                    'table_session_id' => $locked->table_session_id,
                    'waiter_user_id' => $locked->waiter_user_id,
                ],
            );

            $fresh = $locked->fresh();

            WaiterCallAcknowledged::dispatch($fresh->restaurant_id, $fresh->tableSession->table_id, $fresh->table_session_id, $fresh->id, $fresh->waiter_user_id, $fresh->status);

            return $fresh;
        });
    }
}

<?php

namespace App\Actions\Tables;

use App\Events\Realtime\WaiterUnassigned;
use App\Exceptions\TableSessionConflictException;
use App\Models\AuditLog;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Removes the waiter responsible for an active table session, without
 * closing it or touching anything else (orders, requests, payments — see
 * the Bloco 2 report). Locks the session row exactly like
 * AssignWaiterAction so a concurrent unassign/assign can't race.
 *
 * Unassigning an already-unassigned session is a deliberate no-op — no
 * write, no audit event.
 */
class UnassignWaiterAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(TableSession $session, User $actor): TableSession
    {
        return DB::transaction(function () use ($session, $actor) {
            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActive()) {
                throw new TableSessionConflictException('This table session is closed and its waiter cannot be changed.');
            }

            if ($locked->assigned_waiter_user_id === null) {
                return $locked;
            }

            $previousWaiterId = $locked->assigned_waiter_user_id;

            $locked->update(['assigned_waiter_user_id' => null]);

            $this->auditLogger->log(
                organizationId: $locked->restaurant->organization_id,
                restaurantId: $locked->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: AuditLog::EVENT_TABLE_SESSION_WAITER_UNASSIGNED,
                resourceType: AuditLog::RESOURCE_TABLE_SESSION,
                resourceId: $locked->id,
                metadata: [
                    'table_id' => $locked->table_id,
                    'previous_waiter_user_id' => $previousWaiterId,
                ],
            );

            WaiterUnassigned::dispatch($locked->restaurant_id, $locked->id, $locked->table_id, $previousWaiterId, null);

            return $locked->fresh();
        });
    }
}

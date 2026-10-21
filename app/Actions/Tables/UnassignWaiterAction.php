<?php

namespace App\Actions\Tables;

use App\Events\Realtime\WaiterUnassigned;
use App\Exceptions\TableSessionConflictException;
use App\Models\AuditLog;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tables\WaiterAssignmentLock;
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

    public function execute(TableSession $session, ?User $actor, string $source = 'manual'): TableSession
    {
        return DB::transaction(function () use ($session, $actor, $source) {
            WaiterAssignmentLock::acquire($session->restaurant_id);
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
                actorType: $actor ? AuditLog::ACTOR_USER : AuditLog::ACTOR_PUBLIC,
                actor: $actor,
                event: AuditLog::EVENT_TABLE_SESSION_WAITER_UNASSIGNED,
                resourceType: AuditLog::RESOURCE_TABLE_SESSION,
                resourceId: $locked->id,
                metadata: [
                    'table_id' => $locked->table_id,
                    'source' => $source,
                    'previous_waiter_user_id' => $previousWaiterId,
                ],
            );

            app(EnsureSessionWaiterAction::class)->recordPending($locked, $actor, $source);

            WaiterUnassigned::dispatch($locked->restaurant_id, $locked->id, $locked->table_id, $previousWaiterId, null);

            return $locked->fresh();
        });
    }
}

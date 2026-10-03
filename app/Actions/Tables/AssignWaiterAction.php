<?php

namespace App\Actions\Tables;

use App\Events\Realtime\WaiterAssigned;
use App\Events\Realtime\WaiterReassigned;
use App\Exceptions\Tables\WaiterAssignmentIneligibleException;
use App\Exceptions\TableSessionConflictException;
use App\Models\AuditLog;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tables\WaiterAssignmentEligibility;
use Illuminate\Support\Facades\DB;

/**
 * Assigns — or reassigns — the waiter responsible for an active table
 * session. Locks the session row (lockForUpdate) and rechecks both that it
 * is still active and that the candidate is still eligible against
 * freshly-read data, exactly like CloseTableAction/RecordPaymentAction:
 * two concurrent assignments to the same session must never both "win"
 * silently, and eligibility must never be trusted from a stale read.
 *
 * Assigning the waiter already responsible for the session is a
 * deliberate no-op — no write, no audit event (Mateo -> Mateo is not a
 * meaningful transition; see the Bloco 2 report on idempotency).
 */
class AssignWaiterAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(TableSession $session, User $waiter, User $actor): TableSession
    {
        return DB::transaction(function () use ($session, $waiter, $actor) {
            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActive()) {
                throw new TableSessionConflictException('This table session is closed and cannot be assigned a waiter.');
            }

            if ($locked->assigned_waiter_user_id === $waiter->id) {
                return $locked;
            }

            $restaurant = $locked->restaurant;
            $organization = $restaurant->organization;

            $ineligibilityReason = WaiterAssignmentEligibility::ineligibilityReason($waiter, $organization, $restaurant);

            if ($ineligibilityReason !== null) {
                throw new WaiterAssignmentIneligibleException($ineligibilityReason);
            }

            $previousWaiterId = $locked->assigned_waiter_user_id;

            $locked->update(['assigned_waiter_user_id' => $waiter->id]);

            $this->auditLogger->log(
                organizationId: $organization->id,
                restaurantId: $locked->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: $previousWaiterId === null
                    ? AuditLog::EVENT_TABLE_SESSION_WAITER_ASSIGNED
                    : AuditLog::EVENT_TABLE_SESSION_WAITER_REASSIGNED,
                resourceType: AuditLog::RESOURCE_TABLE_SESSION,
                resourceId: $locked->id,
                metadata: [
                    'table_id' => $locked->table_id,
                    'previous_waiter_user_id' => $previousWaiterId,
                    'new_waiter_user_id' => $waiter->id,
                ],
            );

            $event = $previousWaiterId === null ? WaiterAssigned::class : WaiterReassigned::class;
            $event::dispatch($locked->restaurant_id, $locked->id, $locked->table_id, $previousWaiterId, $waiter->id);

            return $locked->fresh();
        });
    }
}

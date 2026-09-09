<?php

namespace App\Actions\Tables;

use App\Events\Realtime\WaiterCallCreated;
use App\Exceptions\Tables\TableSessionHasNoAssignedWaiterException;
use App\Exceptions\Tables\WaiterCallConflictException;
use App\Exceptions\TableSessionConflictException;
use App\Models\AuditLog;
use App\Models\TableSession;
use App\Models\User;
use App\Models\WaiterCall;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Places an internal "call responsible waiter" escalation: Manager/Owner
 * -> the session's CURRENTLY assigned waiter. Requires an active session
 * with an eligible (assigned, not suspended) waiter — an unassigned or
 * suspended-waiter session has no valid destination for the call, so it is
 * rejected rather than silently no-op'd.
 *
 * Deliberately does NOT require an active StaffShift for the waiter — see
 * the Bloco 4 report; that requirement is left as an explicit future
 * decision, not introduced silently here.
 */
class CallResponsibleWaiterAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(TableSession $session, User $actor): WaiterCall
    {
        return DB::transaction(function () use ($session, $actor) {
            $lockedSession = TableSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $lockedSession || ! $lockedSession->isActive()) {
                throw new TableSessionConflictException('This table session is closed.');
            }

            if ($lockedSession->assigned_waiter_user_id === null) {
                throw new TableSessionHasNoAssignedWaiterException('This table session has no assigned waiter to call.');
            }

            $waiter = User::query()->whereKey($lockedSession->assigned_waiter_user_id)->first();

            if (! $waiter || $waiter->isSuspended()) {
                throw new TableSessionHasNoAssignedWaiterException('The assigned waiter is suspended and cannot be called.');
            }

            try {
                $call = WaiterCall::query()->create([
                    'restaurant_id' => $lockedSession->restaurant_id,
                    'table_session_id' => $lockedSession->id,
                    'waiter_user_id' => $waiter->id,
                    'called_by_user_id' => $actor->id,
                    'status' => WaiterCall::STATUS_PENDING,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw new WaiterCallConflictException('This table session already has a pending call.', previous: $e);
            }

            $this->auditLogger->log(
                organizationId: $lockedSession->restaurant->organization_id,
                restaurantId: $lockedSession->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: AuditLog::EVENT_TABLE_SESSION_RESPONSIBLE_WAITER_CALLED,
                resourceType: AuditLog::RESOURCE_WAITER_CALL,
                resourceId: $call->id,
                metadata: [
                    'table_session_id' => $lockedSession->id,
                    'waiter_user_id' => $waiter->id,
                ],
            );

            WaiterCallCreated::dispatch($lockedSession->restaurant_id, $lockedSession->table_id, $lockedSession->id, $call->id, $waiter->id, $call->status);

            return $call;
        });
    }
}

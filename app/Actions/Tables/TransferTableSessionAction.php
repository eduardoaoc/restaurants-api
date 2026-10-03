<?php

namespace App\Actions\Tables;

use App\Events\Realtime\TableSessionTransferred;
use App\Exceptions\TableSessionConflictException;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\Table;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Transfers an active TableSession from its current Table to a target
 * Table of the SAME Restaurant. The session identity never changes — same
 * id, same opened_at, same guest_count, same assigned waiter, same Orders/
 * TableRequests/PaymentRecords. Only the physical table association moves.
 *
 * Order/TableRequest/PaymentRecord each enforce, via their own booted()
 * `saving` hook, that their own table_id must match their
 * table_session_id's CURRENT table_id (see those models). This is not
 * optional bookkeeping: without updating them here, the very next
 * lifecycle action on any existing Order of this session (accept/
 * prepare/serve/...) would immediately fail that invariant. table_id
 * therefore FOLLOWS the session — it is not preserved as a historical
 * snapshot of where an order was originally placed. PrintRecord carries no
 * table_id at all (only restaurant_id/order_id/table_session_id) and is
 * entirely unaffected — see the Bloco 4 report for the full inventory.
 *
 * Locks the session row and the target table row (in that order) so two
 * concurrent transfers targeting the same table can't both succeed; the
 * partial unique index on table_sessions (one active session per table)
 * is the real safety net, exactly like OpenTableAction.
 */
class TransferTableSessionAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(TableSession $session, Table $targetTable, User $actor): TableSession
    {
        return DB::transaction(function () use ($session, $targetTable, $actor) {
            $lockedSession = TableSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $lockedSession || ! $lockedSession->isActive()) {
                throw new TableSessionConflictException('This table session is closed and cannot be transferred.');
            }

            if ($targetTable->id === $lockedSession->table_id) {
                throw new TableSessionConflictException('The session is already at this table.');
            }

            $lockedTargetTable = Table::query()->whereKey($targetTable->id)->lockForUpdate()->first();

            $targetHasActiveSession = TableSession::query()
                ->where('table_id', $lockedTargetTable->id)
                ->where('status', '!=', 'closed')
                ->exists();

            if ($targetHasActiveSession) {
                throw new TableSessionConflictException('The target table already has an active session.');
            }

            $fromTableId = $lockedSession->table_id;

            try {
                $lockedSession->update(['table_id' => $lockedTargetTable->id]);
            } catch (UniqueConstraintViolationException $e) {
                // Lost a race against another transfer/open targeting the
                // same table — the partial unique index is the real guard.
                throw new TableSessionConflictException('The target table already has an active session.', previous: $e);
            }

            // Bulk query-builder updates — deliberately bypass Eloquent
            // model events (no per-row `saving` re-validation needed: the
            // session's table_id is already updated above, so every row
            // ends up consistent with it).
            Order::query()->where('table_session_id', $lockedSession->id)->update(['table_id' => $lockedTargetTable->id]);
            TableRequest::query()->where('table_session_id', $lockedSession->id)->update(['table_id' => $lockedTargetTable->id]);
            PaymentRecord::query()->where('table_session_id', $lockedSession->id)->update(['table_id' => $lockedTargetTable->id]);

            $this->auditLogger->log(
                organizationId: $lockedSession->restaurant->organization_id,
                restaurantId: $lockedSession->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: AuditLog::EVENT_TABLE_SESSION_TRANSFERRED,
                resourceType: AuditLog::RESOURCE_TABLE_SESSION,
                resourceId: $lockedSession->id,
                metadata: [
                    'table_session_id' => $lockedSession->id,
                    'from_table_id' => $fromTableId,
                    'to_table_id' => $lockedTargetTable->id,
                ],
            );

            TableSessionTransferred::dispatch($lockedSession->restaurant_id, $lockedSession->id, $fromTableId, $lockedTargetTable->id);

            return $lockedSession->fresh();
        });
    }
}

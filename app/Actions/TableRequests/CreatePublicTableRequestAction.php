<?php

namespace App\Actions\TableRequests;

use App\Actions\Public\ResolvePublicTableAction;
use App\Exceptions\Billing\TableSessionAlreadyPaidException;
use App\Exceptions\Orders\TableSessionNotActiveException;
use App\Exceptions\Public\BillRequestDisabledException;
use App\Exceptions\Public\WaiterCallDisabledException;
use App\Exceptions\TableRequests\TableRequestAlreadyOpenException;
use App\Models\AuditLog;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a table request (call_waiter/request_bill) from the public QR
 * surface. Resolves the table exactly like GET /public/tables/{token} does
 * (public_token -> Table -> Restaurant -> Organization; never
 * TenantContext) and, like order creation, requires an active
 * TableSession — the session is re-resolved and re-locked here rather than
 * trusted from an earlier GET, since it may have closed in between.
 *
 * Reuses TableSessionNotActiveException from the Orders exceptions
 * namespace rather than declaring a duplicate: it's the exact same public
 * contract (409 TABLE_SESSION_NOT_ACTIVE), just triggered from a different
 * action.
 */
class CreatePublicTableRequestAction
{
    public function __construct(
        private readonly ResolvePublicTableAction $resolvePublicTable,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(string $publicToken, string $type, ?string $note): TableRequest
    {
        $table = $this->resolvePublicTable->execute($publicToken);

        // Feature-enabled check before the active-session check — same
        // ordering as CreatePublicOrderAction, see report.
        $settings = $table->restaurant->settings;

        if ($type === TableRequest::TYPE_CALL_WAITER && ! $settings->waiter_call_enabled) {
            throw new WaiterCallDisabledException;
        }

        if ($type === TableRequest::TYPE_REQUEST_BILL && ! $settings->bill_request_enabled) {
            throw new BillRequestDisabledException;
        }

        $session = $table->activeSession;

        if (! $session) {
            throw new TableSessionNotActiveException;
        }

        return DB::transaction(function () use ($table, $session, $type, $note) {
            $lockedSession = TableSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $lockedSession || ! $lockedSession->isActive()) {
                throw new TableSessionNotActiveException;
            }

            if ($lockedSession->isPaid()) {
                throw new TableSessionAlreadyPaidException;
            }

            $hasOpenRequestOfType = TableRequest::query()
                ->where('table_session_id', $lockedSession->id)
                ->where('type', $type)
                ->whereIn('status', TableRequest::openStatuses())
                ->exists();

            if ($hasOpenRequestOfType) {
                throw new TableRequestAlreadyOpenException;
            }

            try {
                $tableRequest = TableRequest::query()->create([
                    'restaurant_id' => $table->restaurant_id,
                    'table_id' => $table->id,
                    'table_session_id' => $lockedSession->id,
                    'type' => $type,
                    'status' => TableRequest::STATUS_PENDING,
                    'note' => $note,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Lost a race against a concurrent duplicate submit — the
                // partial unique index is the real guard; the exists()
                // check above is only a friendlier fast path.
                throw new TableRequestAlreadyOpenException(previous: $e);
            }

            $this->auditLogger->log(
                organizationId: $table->restaurant->organization_id,
                restaurantId: $table->restaurant_id,
                actorType: AuditLog::ACTOR_PUBLIC,
                actor: null,
                event: AuditLog::EVENT_TABLE_REQUEST_CREATED,
                resourceType: AuditLog::RESOURCE_TABLE_REQUEST,
                resourceId: $tableRequest->id,
                metadata: [
                    'type' => $tableRequest->type,
                    'status' => $tableRequest->status,
                    'table_session_id' => $tableRequest->table_session_id,
                ],
            );

            return $tableRequest;
        });
    }
}

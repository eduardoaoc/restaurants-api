<?php

namespace App\Actions\TableRequests;

use App\Actions\Public\ResolvePublicTableAction;
use App\Actions\Tables\EnsureSessionWaiterAction;
use App\Events\Realtime\TableRequestCreated;
use App\Exceptions\Billing\TableSessionAlreadyPaidException;
use App\Exceptions\Billing\TableSessionHasNoBillableOrdersException;
use App\Exceptions\Billing\TableSessionHasOpenOrdersException;
use App\Exceptions\Orders\TableSessionNotActiveException;
use App\Exceptions\Public\BillRequestDisabledException;
use App\Exceptions\Public\WaiterCallDisabledException;
use App\Exceptions\TableRequests\TableRequestAlreadyOpenException;
use App\Models\AuditLog;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Support\Activity\ActivityActor;
use App\Support\Activity\RestaurantActivityRecorder;
use App\Support\Activity\RestaurantActivityType;
use App\Support\Audit\AuditLogger;
use App\Support\Billing\SessionBillCalculator;
use App\Support\DailyOperation\DailyOperationGuard;
use App\Support\Restaurants\RestaurantOperationalLock;
use App\Support\Tables\WaiterAssignmentLock;
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
        private readonly RestaurantActivityRecorder $activityRecorder,
    ) {}

    public function execute(string $publicToken, string $type, ?string $note, ?string $visitToken = null): TableRequest
    {
        $table = $this->resolvePublicTable->execute($publicToken, $visitToken);

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

        if (! $session || ! $session->isActive()) {
            throw new TableSessionNotActiveException;
        }

        return DB::transaction(function () use ($table, $session, $type, $note) {
            // Operational lock first (deadlock rule), then the day gate: no
            // new public request once the Cierre Diario has closed the day.
            RestaurantOperationalLock::shared($table->restaurant_id);
            WaiterAssignmentLock::acquire($table->restaurant_id);
            DailyOperationGuard::requireOpen($table->restaurant_id);
            $lockedSession = TableSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $lockedSession || ! $lockedSession->isActive()
                || $lockedSession->table_id !== $table->id || $lockedSession->restaurant_id !== $table->restaurant_id) {
                throw new TableSessionNotActiveException;
            }

            // A paid-but-still-active session may still call the waiter
            // (post-payment "want anything else?" CTA, CARTA 5.1C) — it's
            // not a financial operation. Every other type, request_bill
            // included, stays blocked once paid.
            if ($lockedSession->isPaid() && $type !== TableRequest::TYPE_CALL_WAITER) {
                throw new TableSessionAlreadyPaidException;
            }

            // The bill can only be requested once the service is done:
            // at least one billable order and none still in
            // approval/kitchen/delivery. Same summary and check order as
            // CloseTableAction, evaluated under the session lock (order
            // creation takes the same lock). call_waiter is unaffected.
            if ($type === TableRequest::TYPE_REQUEST_BILL) {
                $summary = SessionBillCalculator::summarize($lockedSession);

                if ($summary['hasOpenOrders']) {
                    throw new TableSessionHasOpenOrdersException;
                }

                if (! $summary['hasBillableOrders']) {
                    throw new TableSessionHasNoBillableOrdersException;
                }
            }

            $lockedSession = app(EnsureSessionWaiterAction::class)->execute($lockedSession, source: 'table_request');

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

            $this->activityRecorder->record(
                restaurantId: $table->restaurant_id,
                type: $tableRequest->type === TableRequest::TYPE_CALL_WAITER
                    ? RestaurantActivityType::WAITER_REQUEST_CREATED
                    : RestaurantActivityType::BILL_REQUEST_CREATED,
                actor: ActivityActor::customer(),
                table: $table,
                tableSessionId: $lockedSession->id,
                tableRequestId: $tableRequest->id,
                occurredAt: $tableRequest->created_at,
            );

            TableRequestCreated::dispatch($table->restaurant_id, $table->id, $lockedSession->id, $tableRequest->id, $tableRequest->type, $tableRequest->status);

            return $tableRequest;
        });
    }
}

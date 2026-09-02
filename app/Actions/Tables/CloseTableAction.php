<?php

namespace App\Actions\Tables;

use App\Exceptions\Billing\TableSessionClosedException;
use App\Exceptions\Billing\TableSessionHasNoBillableOrdersException;
use App\Exceptions\Billing\TableSessionHasOpenOrdersException;
use App\Exceptions\Billing\TableSessionNotPaidException;
use App\Models\AuditLog;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Closes a table session — the one explicit, manual action that ends the
 * operational lifecycle. Locks the session row (lockForUpdate) and
 * rechecks every precondition against freshly-read data rather than
 * trusting the $session instance passed in, so a second, concurrent close
 * attempt (or a close racing a payment/order) always observes the
 * up-to-date state.
 *
 * Precondition order matters and mirrors the report's worked examples: a
 * session with any order still in waiting_approval/confirmed/accepted/
 * preparing/ready is rejected as "has open orders" even if none of those
 * orders are billable yet (e.g. a lone waiting_approval order) — only once
 * there are zero open orders do we ask whether there was anything billable
 * at all, and only then whether it was fully paid. This order is what
 * makes "close with only cancelled orders" correctly report "no billable
 * orders" rather than "not paid".
 */
class CloseTableAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(TableSession $session, User $closedBy): TableSession
    {
        return DB::transaction(function () use ($session, $closedBy) {
            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActive()) {
                throw new TableSessionClosedException;
            }

            $summary = SessionBillCalculator::summarize($locked);

            if ($summary['hasOpenOrders']) {
                throw new TableSessionHasOpenOrdersException;
            }

            if (! $summary['hasBillableOrders']) {
                throw new TableSessionHasNoBillableOrdersException;
            }

            // Defensive recheck: never trust payment_status alone.
            if (! $locked->isPaid() || $summary['balanceCents'] !== 0) {
                throw new TableSessionNotPaidException;
            }

            $locked->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by_user_id' => $closedBy->id,
            ]);

            $organizationId = $locked->restaurant->organization_id;

            $this->auditLogger->log(
                organizationId: $organizationId,
                restaurantId: $locked->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $closedBy,
                event: AuditLog::EVENT_TABLE_SESSION_CLOSED,
                resourceType: AuditLog::RESOURCE_TABLE_SESSION,
                resourceId: $locked->id,
                metadata: [
                    'table_id' => $locked->table_id,
                    'payment_status' => $locked->payment_status,
                    'orders_total' => Money::centsToDecimal($summary['ordersTotalCents']),
                    'paid_total' => Money::centsToDecimal($summary['paidTotalCents']),
                ],
            );

            // Any still-open request becomes irrelevant once the session
            // ends — cancelled, not completed, and with no special case
            // for request_bill: the session closing is itself the answer
            // to "please bring the bill". Each one is a real state
            // transition, so each gets its own audit event too.
            TableRequest::query()
                ->where('table_session_id', $locked->id)
                ->whereIn('status', TableRequest::openStatuses())
                ->get()
                ->each(function (TableRequest $tableRequest) use ($closedBy, $organizationId) {
                    $previousStatus = $tableRequest->status;

                    $tableRequest->update([
                        'status' => TableRequest::STATUS_CANCELLED,
                        'cancelled_by_user_id' => $closedBy->id,
                        'cancelled_at' => now(),
                    ]);

                    $this->auditLogger->log(
                        organizationId: $organizationId,
                        restaurantId: $tableRequest->restaurant_id,
                        actorType: AuditLog::ACTOR_USER,
                        actor: $closedBy,
                        event: AuditLog::EVENT_TABLE_REQUEST_CANCELLED,
                        resourceType: AuditLog::RESOURCE_TABLE_REQUEST,
                        resourceId: $tableRequest->id,
                        metadata: [
                            'previous_status' => $previousStatus,
                            'new_status' => TableRequest::STATUS_CANCELLED,
                            'type' => $tableRequest->type,
                            'reason' => 'table_session_closed',
                        ],
                    );
                });

            return $locked->fresh();
        });
    }
}

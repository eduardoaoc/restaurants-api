<?php

namespace App\Actions\Tables;

use App\Exceptions\Billing\TableSessionClosedException;
use App\Exceptions\Billing\TableSessionHasNoBillableOrdersException;
use App\Exceptions\Billing\TableSessionHasOpenOrdersException;
use App\Exceptions\Billing\TableSessionNotPaidException;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Billing\SessionBillCalculator;
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

            // Any still-open request becomes irrelevant once the session
            // ends — cancelled, not completed, and with no special case
            // for request_bill: the session closing is itself the answer
            // to "please bring the bill".
            TableRequest::query()
                ->where('table_session_id', $locked->id)
                ->whereIn('status', TableRequest::openStatuses())
                ->get()
                ->each(function (TableRequest $tableRequest) use ($closedBy) {
                    $tableRequest->update([
                        'status' => TableRequest::STATUS_CANCELLED,
                        'cancelled_by_user_id' => $closedBy->id,
                        'cancelled_at' => now(),
                    ]);
                });

            return $locked->fresh();
        });
    }
}

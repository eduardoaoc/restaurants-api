<?php

namespace App\Actions\Public;

use App\Models\TableSession;
use App\Support\Billing\SessionBillCalculator;

/**
 * Derives the public, presentation-only "can this visit ask for the bill
 * right now?" state (CARTA 5.1D) so the QR client never has to replicate
 * Order::billableStatuses()/openStatuses() itself. Only a boolean and a
 * coarse reason leave the backend — no counts, ids, statuses or amounts.
 *
 * Deliberately NOT the authority: POST /public/tables/{token}/requests/bill
 * (CreatePublicTableRequestAction) re-checks everything under the session
 * lock and decides the real outcome. The restaurant's bill_request_enabled
 * flag is not folded in either — it is already exposed as
 * restaurant.capabilities.bill_request, and the client combines both.
 *
 * Reason priority is chosen for the guest, and intentionally differs from
 * the POST's exception order (which checks open/billable orders before the
 * already-open request): once the bill has been asked for, "already
 * requested" stays the most useful message even if staff later add an
 * order that is still open (staff orders remain allowed after a bill
 * request — OrderCreationService is unchanged).
 *
 *   no_active_session > already_paid > already_requested > open_orders > no_billable_orders
 *
 * Cost: 0 queries without an unpaid active session; otherwise 1 exists()
 * for the open request_bill, plus 1 distinct-status query on orders when
 * that is not already the answer — constant regardless of order count.
 */
class ResolvePublicBillRequestStateAction
{
    public const REASON_NO_ACTIVE_SESSION = 'no_active_session';

    public const REASON_ALREADY_PAID = 'already_paid';

    public const REASON_ALREADY_REQUESTED = 'already_requested';

    public const REASON_OPEN_ORDERS = 'open_orders';

    public const REASON_NO_BILLABLE_ORDERS = 'no_billable_orders';

    public const REASONS = [
        self::REASON_NO_ACTIVE_SESSION,
        self::REASON_ALREADY_PAID,
        self::REASON_ALREADY_REQUESTED,
        self::REASON_OPEN_ORDERS,
        self::REASON_NO_BILLABLE_ORDERS,
    ];

    /**
     * @return array{eligible: bool, reason: string|null}
     */
    public function execute(?TableSession $session): array
    {
        $reason = $this->reason($session);

        return ['eligible' => $reason === null, 'reason' => $reason];
    }

    private function reason(?TableSession $session): ?string
    {
        if ($session === null || ! $session->isActive()) {
            return self::REASON_NO_ACTIVE_SESSION;
        }

        if ($session->isPaid()) {
            return self::REASON_ALREADY_PAID;
        }

        if ($session->hasOpenBillRequest()) {
            return self::REASON_ALREADY_REQUESTED;
        }

        $serviceState = SessionBillCalculator::serviceState($session);

        if ($serviceState['hasOpenOrders']) {
            return self::REASON_OPEN_ORDERS;
        }

        if (! $serviceState['hasBillableOrders']) {
            return self::REASON_NO_BILLABLE_ORDERS;
        }

        return null;
    }
}

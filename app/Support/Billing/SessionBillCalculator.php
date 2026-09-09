<?php

namespace App\Support\Billing;

use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\TableSession;
use App\Support\Money\Money;

/**
 * Computes a table session's financial summary from its Orders'
 * already-persisted totals and its PaymentRecords' amounts — never by
 * recalculating from Product/RestaurantProduct/ModifierOption. All math is
 * done in integer cents via Money; only the final consumer formats back to
 * decimal strings.
 *
 * Used by both RecordPaymentAction/CloseTableAction (business rule checks)
 * and the bill endpoint (display) so the numbers can never drift apart.
 */
class SessionBillCalculator
{
    /**
     * @return array{ordersTotalCents: int, paidTotalCents: int, balanceCents: int, hasBillableOrders: bool, hasOpenOrders: bool}
     */
    public static function summarize(TableSession $session): array
    {
        $orders = $session->orders()->get(['id', 'status', 'total']);

        $billableOrders = $orders->whereIn('status', Order::billableStatuses());
        $hasOpenOrders = $orders->whereIn('status', Order::openStatuses())->isNotEmpty();

        $ordersTotalCents = $billableOrders->sum(fn (Order $order) => Money::decimalToCents((string) $order->total));
        $paidTotalCents = $session->paymentRecords()->get(['amount'])
            ->sum(fn ($payment) => Money::decimalToCents((string) $payment->amount));

        return [
            'ordersTotalCents' => $ordersTotalCents,
            'paidTotalCents' => $paidTotalCents,
            'balanceCents' => $ordersTotalCents - $paidTotalCents,
            'hasBillableOrders' => $billableOrders->isNotEmpty(),
            'hasOpenOrders' => $hasOpenOrders,
        ];
    }

    /**
     * @param  array{ordersTotalCents: int, paidTotalCents: int, balanceCents: int, hasBillableOrders: bool, hasOpenOrders: bool}  $summary
     */
    public static function canClose(TableSession $session, array $summary): bool
    {
        return $session->isActive()
            && $summary['hasBillableOrders']
            && ! $summary['hasOpenOrders']
            && $session->isPaid()
            && $summary['balanceCents'] === 0;
    }

    /**
     * Bulk variant of summarize() for the Operations Live snapshot (Bloco
     * 5): computes ordersTotalCents/paidTotalCents/balanceCents/
     * hasBillableOrders for MANY sessions in exactly two aggregate queries
     * (GROUP BY table_session_id) total, regardless of how many session
     * ids are passed — never one summarize() call per session, which would
     * be N+1. Does not compute hasOpenOrders: a caller that already needs
     * per-session open-order data for another reason (e.g. the live
     * snapshot's own kitchen/table-state queries) is expected to derive it
     * from that same data instead of this method running a third query for
     * information it already has.
     *
     * @param  array<int, int>  $sessionIds
     * @return array<int, array{ordersTotalCents: int, paidTotalCents: int, balanceCents: int, hasBillableOrders: bool}>
     */
    public static function summarizeMany(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $orderTotals = Order::query()
            ->whereIn('table_session_id', $sessionIds)
            ->whereIn('status', Order::billableStatuses())
            ->select('table_session_id')
            ->selectRaw('COALESCE(SUM(total), 0) as total_amount')
            ->groupBy('table_session_id')
            ->get()
            ->keyBy('table_session_id');

        $paidTotals = PaymentRecord::query()
            ->whereIn('table_session_id', $sessionIds)
            ->select('table_session_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as paid_amount')
            ->groupBy('table_session_id')
            ->get()
            ->keyBy('table_session_id');

        $result = [];

        foreach ($sessionIds as $sessionId) {
            $orderRow = $orderTotals->get($sessionId);
            $ordersTotalCents = Money::decimalToCents((string) ($orderRow->total_amount ?? '0'));
            $paidTotalCents = Money::decimalToCents((string) ($paidTotals->get($sessionId)->paid_amount ?? '0'));

            $result[$sessionId] = [
                'ordersTotalCents' => $ordersTotalCents,
                'paidTotalCents' => $paidTotalCents,
                'balanceCents' => $ordersTotalCents - $paidTotalCents,
                'hasBillableOrders' => $orderRow !== null,
            ];
        }

        return $result;
    }
}

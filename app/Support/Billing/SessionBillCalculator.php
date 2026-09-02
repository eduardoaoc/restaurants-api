<?php

namespace App\Support\Billing;

use App\Models\Order;
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
}

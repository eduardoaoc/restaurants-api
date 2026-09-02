<?php

namespace App\Actions\Printing;

use App\Http\Resources\Api\V1\Printing\BillReceiptResource;
use App\Http\Resources\Api\V1\Printing\ReceiptOrderItemModifierResource;
use App\Http\Resources\Api\V1\Printing\ReceiptOrderItemResource;
use App\Http\Resources\Api\V1\Printing\ReceiptOrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\TableSession;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;

/**
 * Builds the bill receipt document for a table session: only billable
 * orders (Order::billableStatuses() — the same rule Bloco 13 uses for the
 * bill), itemized from each order's own persisted snapshots, with totals
 * computed exclusively via SessionBillCalculator so this document can
 * never drift from GET /table-sessions/{id}/bill.
 */
class BuildBillReceiptAction
{
    public function execute(TableSession $session): BillReceiptResource
    {
        $session->loadMissing(['restaurant', 'table', 'paymentRecords.recordedBy']);

        $orders = $session->orders()
            ->whereIn('status', Order::billableStatuses())
            ->orderBy('created_at')
            ->orderBy('id')
            ->with([
                'items' => fn ($query) => $query->orderBy('id'),
                'items.modifiers' => fn ($query) => $query->orderBy('id'),
            ])
            ->get();

        $orderResources = $orders->map(function (Order $order) {
            $itemResources = $order->items->map(function (OrderItem $item) {
                $modifierResources = $item->modifiers
                    ->map(fn (OrderItemModifier $modifier) => new ReceiptOrderItemModifierResource($modifier))
                    ->all();

                return new ReceiptOrderItemResource($item, $modifierResources);
            })->all();

            return new ReceiptOrderResource($order, $itemResources);
        })->all();

        $summary = SessionBillCalculator::summarize($session);

        return new BillReceiptResource(
            $session,
            $orderResources,
            Money::centsToDecimal($summary['ordersTotalCents']),
            Money::centsToDecimal($summary['paidTotalCents']),
            Money::centsToDecimal($summary['balanceCents']),
        );
    }
}

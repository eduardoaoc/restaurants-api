<?php

namespace App\Http\Resources\Api\V1\Printing;

use App\Http\Resources\Api\V1\PaymentRecordResource;
use App\Models\PrintRecord;
use App\Models\TableSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The bill receipt document: an operational receipt, not a fiscal
 * document (no VAT breakdown, invoice number, or legal identifiers — see
 * BuildBillReceiptAction). Available before payment, after partial
 * payment, after full payment, and after the session closes — a receipt
 * is historical/reprintable, never gated by payment or session state.
 *
 * @mixin TableSession
 */
class BillReceiptResource extends JsonResource
{
    /**
     * @param  array<int, ReceiptOrderResource>  $orders
     */
    public function __construct(
        TableSession $resource,
        private readonly array $orders,
        private readonly string $ordersTotal,
        private readonly string $paidTotal,
        private readonly string $balance,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'document_type' => PrintRecord::DOCUMENT_TYPE_BILL_RECEIPT,
            'restaurant' => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ],
            'table' => [
                'id' => $this->table->id,
                'name' => $this->table->name,
                'number' => $this->table->number,
            ],
            'table_session_id' => $this->id,
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
            'orders' => $this->orders,
            'orders_total' => $this->ordersTotal,
            'paid_total' => $this->paidTotal,
            'balance' => $this->balance,
            'payment_status' => $this->payment_status,
            'payments' => PaymentRecordResource::collection($this->whenLoaded('paymentRecords')),
            'generated_at' => now(),
        ];
    }
}

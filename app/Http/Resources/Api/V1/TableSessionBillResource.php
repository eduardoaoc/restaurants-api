<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TableSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TableSession
 */
class TableSessionBillResource extends JsonResource
{
    public function __construct(
        TableSession $resource,
        private readonly string $ordersTotal,
        private readonly string $paidTotal,
        private readonly string $balance,
        private readonly bool $canClose,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'table_session_id' => $this->id,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'table' => [
                'id' => $this->table->id,
                'name' => $this->table->name,
            ],
            'orders_total' => $this->ordersTotal,
            'paid_total' => $this->paidTotal,
            'balance' => $this->balance,
            'can_close' => $this->canClose,
            'orders' => BillOrderSummaryResource::collection($this->whenLoaded('orders')),
            'payments' => PaymentRecordResource::collection($this->whenLoaded('paymentRecords')),
        ];
    }
}

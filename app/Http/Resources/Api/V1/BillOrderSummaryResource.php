<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bill order with historical item/modifier snapshots.
 *
 * @mixin Order
 */
class BillOrderSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'billable' => in_array($this->status, Order::billableStatuses(), true),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'total' => (string) $this->total,
            'created_at' => $this->created_at,
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => sprintf('#%d', $this->id),
            'origin' => $this->origin,
            'status' => $this->status,
            'restaurant' => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ],
            'table' => [
                'id' => $this->table->id,
                'name' => $this->table->name,
            ],
            'customer_name' => $this->customer_name,
            'customer_note' => $this->customer_note,
            'subtotal' => (string) $this->subtotal,
            'modifiers_total' => (string) $this->modifiers_total,
            'total' => (string) $this->total,
            'created_by_user_id' => $this->created_by_user_id,
            'approved_by_user_id' => $this->approved_by_user_id,
            'cancelled_by_user_id' => $this->cancelled_by_user_id,
            'approved_at' => $this->approved_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}

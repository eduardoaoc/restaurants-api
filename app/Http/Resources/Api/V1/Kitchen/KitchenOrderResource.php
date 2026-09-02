<?php

namespace App\Http\Resources\Api\V1\Kitchen;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean, non-financial projection of an order for the Kitchen Display
 * System: no price/subtotal/modifiers_total/total anywhere, and no
 * created_by/approved_by identities — the kitchen only needs to know what
 * to make and how long it's been waiting. Includes the restaurant because
 * an organization-level user's queue can span more than one unit.
 *
 * @mixin Order
 */
class KitchenOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'origin' => $this->origin,
            'restaurant' => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ],
            'table' => [
                'id' => $this->table->id,
                'name' => $this->table->name,
                'number' => $this->table->number,
            ],
            'order_note' => $this->customer_note,
            'created_at' => $this->created_at,
            'elapsed_seconds' => max(0, now()->timestamp - $this->created_at->timestamp),
            'items' => KitchenOrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}

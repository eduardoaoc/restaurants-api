<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Http\Resources\Api\V1\OrderItemResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean public projection of a just-created order: no created_by/approved_by,
 * no restaurant/table objects, no internal metadata. Reuses OrderItemResource
 * for the items — it only carries snapshot data, nothing admin-only.
 *
 * @mixin Order
 */
class PublicOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => sprintf('#%d', $this->id),
            'status' => $this->status,
            'subtotal' => (string) $this->subtotal,
            'modifiers_total' => (string) $this->modifiers_total,
            'total' => (string) $this->total,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}

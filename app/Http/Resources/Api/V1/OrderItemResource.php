<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Name/description/price fields are historical snapshots taken at order
 * time — never the product's current name or the RestaurantProduct's
 * current price. Safe to reuse from PublicOrderResource: it carries no
 * admin-only identifiers.
 *
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_product_id' => $this->restaurant_product_id,
            'product_id' => $this->product_id,
            'name' => $this->product_name_snapshot,
            'description' => $this->product_description_snapshot,
            'unit_price' => (string) $this->unit_price_snapshot,
            'quantity' => $this->quantity,
            'modifiers_unit_total' => (string) $this->modifiers_unit_total_snapshot,
            'unit_total' => (string) $this->unit_total_snapshot,
            'line_total' => (string) $this->line_total_snapshot,
            'note' => $this->customer_note,
            'modifiers' => OrderItemModifierResource::collection($this->whenLoaded('modifiers')),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Snapshot-only projection of an order line for the public visit summary:
 * the name/prices recorded when the order was placed, never the current
 * catalog. Field names match OrderItemResource/OrderItemModifierResource,
 * minus every id and the free-text note.
 *
 * @mixin OrderItem
 */
class PublicVisitOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->product_name_snapshot,
            'quantity' => $this->quantity,
            'unit_price' => (string) $this->unit_price_snapshot,
            'modifiers' => $this->modifiers->map(fn ($modifier) => [
                'group_name' => $modifier->modifier_group_name_snapshot,
                'name' => $modifier->modifier_option_name_snapshot,
                'price_delta' => (string) $modifier->price_delta_snapshot,
            ])->values()->all(),
            'line_total' => (string) $this->line_total_snapshot,
        ];
    }
}

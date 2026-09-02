<?php

namespace App\Http\Resources\Api\V1\Printing;

use App\Http\Resources\Api\V1\Kitchen\KitchenOrderItemResource;
use App\Models\Order;
use App\Models\PrintRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The kitchen ticket document: a print-ready projection of an order, not
 * the administrative OrderResource. Reuses KitchenOrderItemResource (and,
 * through it, KitchenOrderItemModifierResource) from the KDS — same
 * snapshot-only, no-financial-data shape the kitchen already sees on
 * screen, just wrapped as a standalone printable document.
 *
 * @mixin Order
 */
class KitchenTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'document_type' => PrintRecord::DOCUMENT_TYPE_KITCHEN_TICKET,
            'restaurant' => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ],
            'order' => [
                'id' => $this->id,
                'status' => $this->status,
                'origin' => $this->origin,
                'created_at' => $this->created_at,
            ],
            'table' => [
                'id' => $this->table->id,
                'name' => $this->table->name,
                'number' => $this->table->number,
            ],
            'order_note' => $this->customer_note,
            'items' => KitchenOrderItemResource::collection($this->whenLoaded('items')),
            'generated_at' => now(),
        ];
    }
}

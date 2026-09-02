<?php

namespace App\Http\Resources\Api\V1\Kitchen;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
class KitchenOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->product_name_snapshot,
            'quantity' => $this->quantity,
            'note' => $this->customer_note,
            'modifiers' => KitchenOrderItemModifierResource::collection($this->whenLoaded('modifiers')),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1\Kitchen;

use App\Models\OrderItemModifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kitchen needs to know what was selected, not what it costs. Names come
 * from the order's own snapshots — never the current ModifierGroup/
 * ModifierOption — so the ticket never changes after the fact.
 *
 * @mixin OrderItemModifier
 */
class KitchenOrderItemModifierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'group_name' => $this->modifier_group_name_snapshot,
            'name' => $this->modifier_option_name_snapshot,
        ];
    }
}

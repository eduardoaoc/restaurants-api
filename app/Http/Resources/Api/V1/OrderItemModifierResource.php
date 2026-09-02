<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OrderItemModifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Every textual/price field here is a historical snapshot, never a live
 * lookup of the current ModifierGroup/ModifierOption — see the model
 * comment on OrderItemModifier.
 *
 * @mixin OrderItemModifier
 */
class OrderItemModifierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'modifier_group_id' => $this->modifier_group_id,
            'modifier_option_id' => $this->modifier_option_id,
            'group_name' => $this->modifier_group_name_snapshot,
            'name' => $this->modifier_option_name_snapshot,
            'price_delta' => (string) $this->price_delta_snapshot,
        ];
    }
}

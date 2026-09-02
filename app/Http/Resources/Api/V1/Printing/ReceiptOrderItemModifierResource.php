<?php

namespace App\Http\Resources\Api\V1\Printing;

use App\Models\OrderItemModifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItemModifier
 */
class ReceiptOrderItemModifierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->modifier_option_name_snapshot,
            'price_delta' => (string) $this->price_delta_snapshot,
        ];
    }
}

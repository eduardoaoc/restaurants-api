<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean order summary for the bill view — full items/modifiers aren't
 * needed here (GET /orders/{order} already provides that).
 *
 * @mixin Order
 */
class BillOrderSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'total' => (string) $this->total,
            'created_at' => $this->created_at,
        ];
    }
}

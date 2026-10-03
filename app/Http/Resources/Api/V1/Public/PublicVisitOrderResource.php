<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One billable order inside a public visit summary. `order_number` uses
 * the same "#<id>" display format as PublicOrderResource; no raw id,
 * status, creator/approver or other internal fields.
 *
 * @mixin Order
 */
class PublicVisitOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_number' => sprintf('#%d', $this->id),
            'created_at' => $this->created_at,
            'total' => (string) $this->total,
            'items' => PublicVisitOrderItemResource::collection($this->items),
        ];
    }
}

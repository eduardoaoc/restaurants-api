<?php

namespace App\Http\Resources\Api\V1\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** No customer name, notes, staff identities, payment data or credentials. */
class PublicOrderTrackingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => sprintf('#%d', $this->id),
            'status' => $this->status,
            'created_at' => $this->created_at,
            'total' => (string) $this->total,
            'items' => PublicVisitOrderItemResource::collection($this->items),
        ];
    }
}

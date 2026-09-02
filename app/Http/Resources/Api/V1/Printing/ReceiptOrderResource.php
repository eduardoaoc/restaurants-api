<?php

namespace App\Http\Resources\Api\V1\Printing;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class ReceiptOrderResource extends JsonResource
{
    /**
     * @param  array<int, ReceiptOrderItemResource>  $items
     */
    public function __construct(Order $resource, private readonly array $items)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'total' => (string) $this->total,
            'items' => $this->items,
        ];
    }
}

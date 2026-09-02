<?php

namespace App\Http\Resources\Api\V1\Printing;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Every field here is a historical snapshot (product_name_snapshot,
 * unit_price_snapshot, line_total_snapshot, ...) — never the current
 * catalog. See BuildBillReceiptAction.
 *
 * @mixin OrderItem
 */
class ReceiptOrderItemResource extends JsonResource
{
    /**
     * @param  array<int, ReceiptOrderItemModifierResource>  $modifiers
     */
    public function __construct(OrderItem $resource, private readonly array $modifiers)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->product_name_snapshot,
            'quantity' => $this->quantity,
            'unit_price' => (string) $this->unit_price_snapshot,
            'modifiers' => $this->modifiers,
            'line_total' => (string) $this->line_total_snapshot,
        ];
    }
}

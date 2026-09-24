<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\TableSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public post-payment projection of a visit (CARTA 5.1A) — the minimum the
 * customer needs to review what was consumed. Never exposes internal ids
 * (session/restaurant/table/order/item/user), payment records or methods,
 * staff identities, or internal notes. `summary.total` is computed by the
 * caller via SessionBillCalculator, so it always matches the internal bill.
 *
 * @mixin TableSession
 */
class PublicVisitResource extends JsonResource
{
    public function __construct(TableSession $session, private readonly string $total)
    {
        parent::__construct($session);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'restaurant' => [
                'name' => $this->restaurant->name,
            ],
            'table' => [
                'name' => $this->table->name,
            ],
            'visit' => [
                'active' => $this->isActive(),
                'paid_at' => $this->paid_at,
                'closed_at' => $this->closed_at,
            ],
            'orders' => PublicVisitOrderResource::collection($this->orders),
            'summary' => [
                'total' => $this->total,
            ],
        ];
    }
}

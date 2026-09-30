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
 * google_review (CARTA 5.3A): {available, url}, derived ONLY from the
 * restaurant's settings.google_review_url — never from any feedback
 * rating nor from whether feedback was submitted (no review gating; the
 * UI decides when to show the CTA). Nothing else from settings leaks.
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
            'google_review' => $this->googleReview(),
        ];
    }

    /**
     * @return array{available: bool, url: string|null}
     */
    private function googleReview(): array
    {
        $url = $this->restaurant->settings?->google_review_url;

        return ['available' => $url !== null, 'url' => $url];
    }
}

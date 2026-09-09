<?php

namespace App\Http\Resources\Api\V1\Analytics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin pass-through wrapper around
 * BuildRestaurantAnalyticsAction's already-fully-shaped output (Bloco 6)
 * — the Action owns the response contract, matching
 * OperationsLiveResource's own role for Operations Live (Bloco 5).
 */
class RestaurantAnalyticsResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $analytics
     */
    public function __construct(private readonly array $analytics)
    {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->analytics;
    }
}

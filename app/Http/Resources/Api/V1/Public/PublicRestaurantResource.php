<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public projection of a Restaurant. `capabilities` lets the frontend hide
 * disabled buttons (call waiter, request bill, order online) without
 * exposing the full administrative RestaurantSettings — deliberately not
 * including customer_order_requires_approval: the frontend never needs to
 * know whether an order will be auto-confirmed or wait for approval, it
 * just submits the order and the backend decides; the Order response's own
 * `status` already communicates the outcome. `default_locale`/
 * `enabled_locales` are exposed because the public language switcher needs
 * them and neither is sensitive.
 *
 * @mixin Restaurant
 */
class PublicRestaurantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = $this->settings;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'default_locale' => $settings->default_locale,
            'enabled_locales' => $settings->enabled_locales,
            'capabilities' => [
                'customer_ordering' => $settings->customer_ordering_enabled,
                'waiter_call' => $settings->waiter_call_enabled,
                'bill_request' => $settings->bill_request_enabled,
            ],
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RestaurantSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RestaurantSettings
 */
class RestaurantSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'default_locale' => $this->default_locale,
            'enabled_locales' => $this->enabled_locales,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'customer_ordering_enabled' => $this->customer_ordering_enabled,
            'customer_order_requires_approval' => $this->customer_order_requires_approval,
            'waiter_call_enabled' => $this->waiter_call_enabled,
            'bill_request_enabled' => $this->bill_request_enabled,
            'kitchen_ticket_printing_enabled' => $this->kitchen_ticket_printing_enabled,
            'bill_receipt_printing_enabled' => $this->bill_receipt_printing_enabled,
            'updated_at' => $this->updated_at,
        ];
    }
}

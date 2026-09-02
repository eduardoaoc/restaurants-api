<?php

namespace App\Http\Requests\Api\V1\Kitchen;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the KDS query filters. restaurant_id's actual scope (does it
 * belong to the active organization, is it in the acting user's
 * RestaurantScope) is a domain/authorization concern handled by
 * KitchenController, not here — this only validates shape.
 */
class KitchenOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'restaurant_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(Order::kitchenQueueStatuses())],
        ];
    }
}

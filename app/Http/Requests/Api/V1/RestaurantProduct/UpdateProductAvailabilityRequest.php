<?php

namespace App\Http\Requests\Api\V1\RestaurantProduct;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'available' => ['required', 'boolean'],
            'price' => ['prohibited'],
            'product_id' => ['prohibited'],
            'restaurant_id' => ['prohibited'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\RestaurantProduct;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantProductRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via RestaurantProductPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'price' => ['sometimes', 'numeric', 'min:0'],
            'available' => ['sometimes', 'boolean'],
        ];
    }
}

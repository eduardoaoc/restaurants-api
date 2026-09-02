<?php

namespace App\Http\Requests\Api\V1\RestaurantProduct;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachRestaurantProductRequest extends FormRequest
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
    public function rules(TenantContext $tenantContext): array
    {
        $organizationId = $tenantContext->getOrganizationId();
        $restaurantId = (int) $this->route('restaurant');

        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('organization_id', $organizationId),
                Rule::unique('restaurant_products', 'product_id')->where('restaurant_id', $restaurantId),
            ],
            'price' => ['required', 'numeric', 'min:0'],
            'available' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Restaurant;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via RestaurantPolicy.
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
        $restaurantId = $this->route('restaurant');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('restaurants', 'slug')
                    ->where('organization_id', $organizationId)
                    ->ignore($restaurantId),
            ],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'timezone' => ['sometimes', 'timezone'],
            'default_locale' => ['sometimes', 'string', 'regex:/^[a-z]{2}(-[A-Z]{2})?$/'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $this->merge([
                'slug' => strtolower(trim((string) $this->input('slug'))),
            ]);
        }
    }
}

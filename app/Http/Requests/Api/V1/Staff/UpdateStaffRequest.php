<?php

namespace App\Http\Requests\Api\V1\Staff;

use App\Models\RestaurantUser;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via StaffPolicy.
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
        $staffId = (int) $this->route('user');

        $currentRestaurantId = RestaurantUser::query()->where('user_id', $staffId)->value('restaurant_id');
        $restaurantId = $this->input('restaurant_id', $currentRestaurantId);

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staffId)],
            'restaurant_id' => [
                'sometimes',
                'integer',
                Rule::exists('restaurants', 'id')->where('organization_id', $organizationId),
            ],
            'role' => [
                'sometimes',
                'string',
                Rule::exists('roles', 'slug'),
                Rule::in(StoreStaffRequest::ALLOWED_ROLES),
            ],
            'sub_id' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('restaurant_users', 'sub_id')
                    ->where('restaurant_id', $restaurantId)
                    ->ignore($staffId, 'user_id'),
            ],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }

        if ($this->has('sub_id')) {
            $this->merge([
                'sub_id' => trim((string) $this->input('sub_id')),
            ]);
        }
    }
}

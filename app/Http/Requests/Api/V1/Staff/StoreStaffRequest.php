<?php

namespace App\Http\Requests\Api\V1\Staff;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    /**
     * Operational roles that may be assigned through this endpoint.
     * Owner is intentionally excluded; ownership has its own future flow.
     *
     * @var array<int, string>
     */
    public const ALLOWED_ROLES = ['manager', 'waiter', 'kitchen', 'cashier'];

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

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'restaurant_id' => [
                'required',
                'integer',
                Rule::exists('restaurants', 'id')->where('organization_id', $organizationId),
            ],
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'slug'),
                Rule::in(self::ALLOWED_ROLES),
            ],
            'sub_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('restaurant_users', 'sub_id')->where('restaurant_id', $this->input('restaurant_id')),
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

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
     * sub_id (employee code) is not an input: the backend generates it
     * (EmployeeCodeGenerator), and a client-sent value is ignored because it
     * is not in the validated set.
     *
     * Get the validation rules that apply to the request.
     *
     * restaurant_assignments requires at least one entry — a staff member
     * with zero restaurants would be an accidental organization-wide
     * escalation, not a valid operational staff member (see
     * UpdateStaffRequest for why the same guarantee matters even more on
     * update).
     *
     * @return array<string, mixed>
     */
    public function rules(TenantContext $tenantContext): array
    {
        $organizationId = $tenantContext->getOrganizationId();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'slug'),
                Rule::in(self::ALLOWED_ROLES),
            ],
            'restaurant_assignments' => ['required', 'array', 'min:1'],
            'restaurant_assignments.*.restaurant_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('restaurants', 'id')->where('organization_id', $organizationId),
            ],
        ];

        return $rules;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }
}

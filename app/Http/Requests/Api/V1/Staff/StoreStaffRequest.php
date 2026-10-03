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

        // sub_id uniqueness is per-restaurant, so each array index needs
        // its own rule scoped to that index's own restaurant_id — not
        // expressible as a single wildcard rule.
        foreach ((array) $this->input('restaurant_assignments', []) as $index => $assignment) {
            $rules["restaurant_assignments.{$index}.sub_id"] = [
                'required',
                'string',
                'max:255',
                Rule::unique('restaurant_users', 'sub_id')->where('restaurant_id', $assignment['restaurant_id'] ?? null),
            ];
        }

        return $rules;
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

        if (is_array($this->input('restaurant_assignments'))) {
            $this->merge([
                'restaurant_assignments' => collect($this->input('restaurant_assignments'))
                    ->map(function ($assignment) {
                        if (is_array($assignment) && isset($assignment['sub_id'])) {
                            $assignment['sub_id'] = trim((string) $assignment['sub_id']);
                        }

                        return $assignment;
                    })
                    ->all(),
            ]);
        }
    }
}

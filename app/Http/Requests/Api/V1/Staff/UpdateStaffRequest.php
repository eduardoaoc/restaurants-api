<?php

namespace App\Http\Requests\Api\V1\Staff;

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
     * restaurant_assignments, when sent, REPLACES the staff member's full
     * restaurant set — it must still have at least 1 entry: an empty array
     * would silently strip every restaurant link and, combined with
     * user_roles.restaurant_id becoming ambiguous, risks turning an
     * operational staff member into something resembling an
     * organization-wide assignment. The Staff API must never be able to
     * produce that escalation, so `min:1` is enforced even on update.
     *
     * @return array<string, mixed>
     */
    public function rules(TenantContext $tenantContext): array
    {
        $organizationId = $tenantContext->getOrganizationId();
        $staffId = (int) $this->route('user');

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staffId)],
            'role' => [
                'sometimes',
                'string',
                Rule::exists('roles', 'slug'),
                Rule::in(StoreStaffRequest::ALLOWED_ROLES),
            ],
            'restaurant_assignments' => ['sometimes', 'array', 'min:1'],
            'restaurant_assignments.*.restaurant_id' => [
                'required_with:restaurant_assignments',
                'integer',
                'distinct',
                Rule::exists('restaurants', 'id')->where('organization_id', $organizationId),
            ],
        ];

        foreach ((array) $this->input('restaurant_assignments', []) as $index => $assignment) {
            $rules["restaurant_assignments.{$index}.sub_id"] = [
                'required_with:restaurant_assignments',
                'string',
                'max:255',
                Rule::unique('restaurant_users', 'sub_id')
                    ->where('restaurant_id', $assignment['restaurant_id'] ?? null)
                    ->ignore($staffId, 'user_id'),
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

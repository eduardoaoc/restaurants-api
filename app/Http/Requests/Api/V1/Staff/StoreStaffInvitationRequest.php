<?php

namespace App\Http\Requests\Api\V1\Staff;

use App\Models\Invitation;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /staff/invitations — same shape and rules as StoreStaffRequest
 * (roles limited to StoreStaffRequest::ALLOWED_ROLES, restaurants of the
 * active organization only, employee code unique per restaurant) minus the
 * password: the invited person chooses their own when accepting.
 *
 * Unlike StoreStaffRequest there is no users.email uniqueness rule: an
 * e-mail that already has an account elsewhere is invited the same way and
 * linked only on acceptance — the response never says whether an account
 * exists.
 */
class StoreStaffInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(TenantContext $tenantContext): array
    {
        $organizationId = $tenantContext->getOrganizationId();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::exists('roles', 'slug'), Rule::in(StoreStaffRequest::ALLOWED_ROLES)],
            'restaurant_assignments' => ['required', 'array', 'min:1'],
            'restaurant_assignments.*.restaurant_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('restaurants', 'id')->where('organization_id', $organizationId),
            ],
        ];

        foreach ((array) $this->input('restaurant_assignments', []) as $index => $assignment) {
            $restaurantId = is_array($assignment) ? ($assignment['restaurant_id'] ?? null) : null;

            $rules["restaurant_assignments.{$index}.sub_id"] = [
                'required',
                'string',
                'max:255',
                Rule::unique('restaurant_users', 'sub_id')->where('restaurant_id', $restaurantId),
                // A code promised to another pending invitation of the
                // same restaurant would make one of the two acceptances fail.
                function (string $attribute, mixed $value, Closure $fail) use ($organizationId, $restaurantId): void {
                    if (! is_string($value) || ! is_numeric($restaurantId)) {
                        return;
                    }

                    $reserved = Invitation::query()
                        ->where('organization_id', $organizationId)
                        ->where('status', Invitation::STATUS_PENDING)
                        ->where('expires_at', '>', now())
                        ->whereJsonContains('restaurant_assignments', [['restaurant_id' => (int) $restaurantId, 'sub_id' => $value]])
                        ->exists();

                    if ($reserved) {
                        $fail('The employee code is already reserved by a pending invitation.');
                    }
                },
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }

        if (is_array($this->input('restaurant_assignments'))) {
            $this->merge([
                'restaurant_assignments' => collect($this->input('restaurant_assignments'))
                    ->map(function ($assignment) {
                        if (is_array($assignment) && is_string($assignment['sub_id'] ?? null)) {
                            $assignment['sub_id'] = trim((string) $assignment['sub_id']);
                        }

                        return $assignment;
                    })
                    ->all(),
            ]);
        }
    }

    /**
     * Assignments reduced to the two validated fields, ids as integers.
     *
     * @return array<int, array{restaurant_id: int, sub_id: string}>
     */
    public function assignments(): array
    {
        return collect($this->validated('restaurant_assignments'))
            ->map(fn (array $assignment) => ['restaurant_id' => (int) $assignment['restaurant_id'], 'sub_id' => (string) $assignment['sub_id']])
            ->values()
            ->all();
    }
}

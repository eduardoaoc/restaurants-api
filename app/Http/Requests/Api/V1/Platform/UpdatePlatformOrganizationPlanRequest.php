<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformOrganizationPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * At least one of plan/subscription_status must be present — a call
     * with neither would be a no-op mutation with a reason attached to
     * nothing.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required_without:subscription_status', 'string', Rule::in(Organization::PLANS)],
            'subscription_status' => ['required_without:plan', 'string', Rule::in(Organization::SUBSCRIPTION_STATUSES)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}

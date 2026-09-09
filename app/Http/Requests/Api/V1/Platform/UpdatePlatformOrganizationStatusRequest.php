<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformOrganizationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only active/suspended are reachable here — inactive is the tenant's
     * own self-service value (see UpdateOrganizationRequest) and is
     * deliberately not something a platform admin sets on their behalf.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                Organization::STATUS_ACTIVE,
                Organization::STATUS_SUSPENDED,
            ])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}

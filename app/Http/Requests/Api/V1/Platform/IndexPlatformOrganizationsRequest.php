<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPlatformOrganizationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in([
                Organization::STATUS_ACTIVE,
                Organization::STATUS_INACTIVE,
                Organization::STATUS_SUSPENDED,
            ])],
            'plan' => ['sometimes', 'string', Rule::in(Organization::PLANS)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

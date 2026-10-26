<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Models\Invitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPlatformInvitationsRequest extends FormRequest
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
            'organization_id' => ['sometimes', 'integer'],
            'state' => ['sometimes', 'string', Rule::in([
                Invitation::STATUS_PENDING,
                Invitation::STATE_EXPIRED,
                Invitation::STATUS_ACCEPTED,
                Invitation::STATUS_REVOKED,
            ])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

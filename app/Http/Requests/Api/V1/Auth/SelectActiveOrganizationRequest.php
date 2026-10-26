<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SelectActiveOrganizationRequest extends FormRequest
{
    /**
     * Membership is checked by AuthController::selectOrganization().
     */
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
            'organization_id' => ['required', 'integer', 'min:1'],
        ];
    }
}

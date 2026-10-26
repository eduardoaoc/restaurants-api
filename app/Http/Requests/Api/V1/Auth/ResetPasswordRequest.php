<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Preview (no password) and confirmation (password + confirmation) share the
 * same credentials; the password rules match the rest of the project
 * (StaffStore / invitation acceptance: min 8, confirmed).
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:128'],
        ];

        if (! $this->routeIs('auth.password.preview')) {
            $rules['password'] = ['required', 'string', 'min:8', 'max:255', 'confirmed'];
        }

        return $rules;
    }
}

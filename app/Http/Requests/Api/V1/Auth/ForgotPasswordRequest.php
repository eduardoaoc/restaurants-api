<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\RestaurantSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ForgotPasswordRequest extends FormRequest
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
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            // Language of the e-mail (the SPA's current locale).
            'locale' => ['sometimes', 'string', Rule::in(RestaurantSettings::SUPPORTED_LOCALES)],
        ];
    }
}

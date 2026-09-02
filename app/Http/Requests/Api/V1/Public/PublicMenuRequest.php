<?php

namespace App\Http\Requests\Api\V1\Public;

use App\Exceptions\Public\InvalidPublicLocaleException;
use App\Support\Locale\LocaleResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PublicMenuRequest extends FormRequest
{
    /**
     * Public endpoint: no authenticated user is required.
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
            'locale' => ['sometimes', 'string', 'regex:'.LocaleResolver::PATTERN],
        ];
    }

    /**
     * Fail with the public API's stable error contract instead of the
     * default `{message, errors}` validation shape.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new InvalidPublicLocaleException;
    }
}

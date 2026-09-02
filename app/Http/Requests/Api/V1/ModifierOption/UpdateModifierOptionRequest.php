<?php

namespace App\Http\Requests\Api\V1\ModifierOption;

use App\Http\Requests\Api\V1\Concerns\ValidatesUniqueTranslationLocales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateModifierOptionRequest extends FormRequest
{
    use ValidatesUniqueTranslationLocales;

    /**
     * Authorization is handled by the controller via ModifierOptionPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'internal_name' => ['sometimes', 'string', 'max:255'],
            'price_delta' => ['sometimes', 'numeric', 'min:0'],
            'available' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'translations' => ['sometimes', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2}(-[A-Z]{2})?$/'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->ensureTranslationLocalesAreUnique($validator));
    }
}

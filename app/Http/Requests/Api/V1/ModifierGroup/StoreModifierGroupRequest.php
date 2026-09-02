<?php

namespace App\Http\Requests\Api\V1\ModifierGroup;

use App\Http\Requests\Api\V1\Concerns\ValidatesModifierSelection;
use App\Http\Requests\Api\V1\Concerns\ValidatesUniqueTranslationLocales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreModifierGroupRequest extends FormRequest
{
    use ValidatesModifierSelection, ValidatesUniqueTranslationLocales;

    /**
     * Authorization is handled by the controller via ModifierGroupPolicy.
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
            'internal_name' => ['required', 'string', 'max:255'],
            'min_select' => ['sometimes', 'integer', 'min:0'],
            'max_select' => ['required', 'integer', 'min:1'],
            'required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2}(-[A-Z]{2})?$/'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->ensureTranslationLocalesAreUnique($validator);

            if ($validator->errors()->has('max_select') || ! $this->filled('max_select')) {
                return;
            }

            $this->ensureSelectionIsConsistent(
                $validator,
                (int) $this->input('min_select', 0),
                (int) $this->input('max_select'),
                $this->boolean('required'),
            );
        });
    }
}

<?php

namespace App\Http\Requests\Api\V1\Product;

use App\Http\Requests\Api\V1\Concerns\ValidatesNutritionValues;
use App\Http\Requests\Api\V1\Concerns\ValidatesUniqueTranslationLocales;
use App\Http\Requests\Api\V1\Rules\NotBlank;
use App\Models\Product;
use App\Support\Locale\LocaleResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    use ValidatesNutritionValues, ValidatesUniqueTranslationLocales;

    /**
     * Authorization is handled by the controller via ProductPolicy.
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
            'sku' => ['nullable', 'string', 'max:255'],
            'internal_name' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:20', 'regex:'.LocaleResolver::PATTERN],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['required', 'string', 'max:500', new NotBlank],
            // 'required' would reject an empty array — but [] is a valid,
            // explicit "no allergens" declaration. 'present' + 'array'
            // rejects only a missing key or an explicit null.
            'allergens' => ['present', 'array'],
            'allergens.*' => ['distinct', 'string', Rule::in(Product::ALLERGEN_CODES)],
            ...$this->nutritionRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->ensureTranslationLocalesAreUnique($validator));
    }
}

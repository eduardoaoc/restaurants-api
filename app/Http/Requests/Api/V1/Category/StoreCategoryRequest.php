<?php

namespace App\Http\Requests\Api\V1\Category;

use App\Http\Requests\Api\V1\Concerns\ValidatesUniqueTranslationLocales;
use App\Models\Menu;
use App\Support\Locale\LocaleResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCategoryRequest extends FormRequest
{
    use ValidatesUniqueTranslationLocales;

    /**
     * Authorization is handled by the controller via CategoryPolicy.
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
        $restaurantId = (int) $this->route('restaurant');
        $menuId = Menu::query()->where('restaurant_id', $restaurantId)->value('id');

        return [
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')->where('menu_id', $menuId),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:20', 'regex:'.LocaleResolver::PATTERN],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->ensureTranslationLocalesAreUnique($validator));
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $this->merge([
                'slug' => strtolower(trim((string) $this->input('slug'))),
            ]);
        }
    }
}

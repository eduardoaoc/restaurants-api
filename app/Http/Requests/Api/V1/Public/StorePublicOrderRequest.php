<?php

namespace App\Http\Requests\Api\V1\Public;

use App\Exceptions\Public\InvalidPublicLocaleException;
use App\Support\Locale\LocaleResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the shape of a public order request. Domain rules (does the
 * RestaurantProduct exist for this restaurant, is it available, are the
 * modifier selections valid, is there an active session...) are
 * deliberately NOT checked here — that's CreatePublicOrderAction/
 * BuildOrderItemsAction's job. This only validates structure/types.
 */
class StorePublicOrderRequest extends FormRequest
{
    /**
     * Public endpoint: no authenticated user is required.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['nullable', 'string', 'max:100'],
            'locale' => ['sometimes', 'string', 'regex:'.LocaleResolver::PATTERN],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.restaurant_product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
            'items.*.modifier_option_ids' => ['sometimes', 'array'],
            'items.*.modifier_option_ids.*' => ['integer'],
        ];
    }

    /**
     * Keep the Bloco 9 locale contract for the one field it governs;
     * everything else falls back to the standard Laravel validation shape
     * (permitted for structural errors — see report).
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('locale')) {
            throw new InvalidPublicLocaleException;
        }

        parent::failedValidation($validator);
    }
}

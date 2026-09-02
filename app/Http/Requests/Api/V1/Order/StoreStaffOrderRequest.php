<?php

namespace App\Http\Requests\Api\V1\Order;

use App\Support\Locale\LocaleResolver;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the shape of a waiter-created order request. Domain rules are
 * checked by CreateStaffOrderAction/BuildOrderItemsAction, not here.
 */
class StoreStaffOrderRequest extends FormRequest
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
            'locale' => ['sometimes', 'string', 'regex:'.LocaleResolver::PATTERN],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.restaurant_product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
            'items.*.modifier_option_ids' => ['sometimes', 'array'],
            'items.*.modifier_option_ids.*' => ['integer'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Restaurant;

use App\Models\RestaurantSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a partial (PATCH) update of a restaurant's settings. Only the
 * documented settings columns are ever accepted — organization_id/
 * restaurant_id can never be sent through this request.
 */
class UpdateRestaurantSettingsRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via RestaurantPolicy.
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
            'default_locale' => ['sometimes', 'string', Rule::in(RestaurantSettings::SUPPORTED_LOCALES)],
            'enabled_locales' => ['sometimes', 'array', 'min:1'],
            'enabled_locales.*' => ['string', 'distinct', Rule::in(RestaurantSettings::SUPPORTED_LOCALES)],
            'currency' => ['sometimes', 'string', Rule::in(RestaurantSettings::SUPPORTED_CURRENCIES)],
            'timezone' => ['sometimes', 'timezone'],
            'customer_ordering_enabled' => ['sometimes', 'boolean'],
            'customer_order_requires_approval' => ['sometimes', 'boolean'],
            'waiter_call_enabled' => ['sometimes', 'boolean'],
            'bill_request_enabled' => ['sometimes', 'boolean'],
            'kitchen_ticket_printing_enabled' => ['sometimes', 'boolean'],
            'bill_receipt_printing_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Validates the FINAL merged state (existing settings + this request's
     * fields), not just the fields sent in isolation: sending
     * enabled_locales without also sending a default_locale still within
     * that new list must fail, even though default_locale by itself would
     * otherwise be valid — see report ("Atomic invariants").
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $current = RestaurantSettings::query()
                ->where('restaurant_id', (int) $this->route('restaurant'))
                ->first();

            if (! $current) {
                return;
            }

            $finalDefaultLocale = $this->input('default_locale', $current->default_locale);
            $finalEnabledLocales = $this->input('enabled_locales', $current->enabled_locales);

            if (! in_array($finalDefaultLocale, $finalEnabledLocales, true)) {
                $validator->errors()->add('default_locale', 'The default locale must be included in enabled_locales.');
            }
        });
    }
}

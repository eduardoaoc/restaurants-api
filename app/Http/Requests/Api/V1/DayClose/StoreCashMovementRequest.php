<?php

namespace App\Http\Requests\Api\V1\DayClose;

use App\Models\RestaurantCashMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of POST /restaurants/{restaurant}/cash-movements (CARTA 9.1A).
 * `amount` is a JSON decimal string ("20.00"), never a float — same rule
 * as payments. Idempotency-Key is mandatory.
 */
class StoreCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(RestaurantCashMovement::TYPES)],
            'amount' => ['required', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['idempotency_key.required' => 'The Idempotency-Key header is required.'];
    }
}

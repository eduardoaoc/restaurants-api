<?php

namespace App\Http\Requests\Api\V1\TableSessions;

use App\Models\PaymentRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the shape of a payment-recording request. Domain rules (session
 * not closed/already paid, balance, idempotency) are RecordPaymentAction's
 * job, not this Request's. Authorization is handled by the controller via
 * TableSessionPolicy.
 *
 * `amount` must be sent as a JSON string ("28.40"), never a bare number —
 * this is the only way to guarantee it never passes through a float at
 * any point in the request lifecycle, all the way to Money::decimalToCents().
 */
class StorePaymentRequest extends FormRequest
{
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
            'method' => ['required', 'string', Rule::in(PaymentRecord::METHODS)],
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}

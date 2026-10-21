<?php

namespace App\Http\Requests\Api\V1\DailyOperation;

use App\Support\Money\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /restaurants/{restaurant}/daily-operations. The opening float is a
 * decimal string (never a float): zero is valid, negatives and more than
 * two decimals are refused, and it is capped at MAX_OPENING_FLOAT — a
 * drawer float, not a deposit. Idempotency-Key mandatory, like the close.
 */
class OpenDailyOperationRequest extends FormRequest
{
    public const MAX_OPENING_FLOAT = '50000.00';

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
            'idempotency_key' => ['required', 'string', 'max:100'],
            'opening_float' => ['required', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value) && Money::decimalToCents($value) > Money::decimalToCents(self::MAX_OPENING_FLOAT)) {
                    $fail('The opening float may not be greater than '.self::MAX_OPENING_FLOAT.'.');
                }
            }],
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

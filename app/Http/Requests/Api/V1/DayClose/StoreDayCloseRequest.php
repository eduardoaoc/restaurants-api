<?php

namespace App\Http\Requests\Api\V1\DayClose;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of POST /restaurants/{restaurant}/day-closes (CARTA 9.1A). Money
 * as decimal strings. Every domain rule (period, blockers, opening float,
 * expected cash, note threshold) is CloseRestaurantDayAction's — under
 * the exclusive operational lock, never here. Idempotency-Key mandatory.
 */
class StoreDayCloseRequest extends FormRequest
{
    private const MONEY = 'regex:/^\d{1,8}(\.\d{1,2})?$/';

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
            'period_started_at' => ['required', 'date'],
            'expected_cash_seen' => ['required', 'string', self::MONEY],
            'opening_float' => ['nullable', 'string', self::MONEY],
            'counted_cash' => ['required', 'string', self::MONEY],
            'cash_left_for_next_day' => ['nullable', 'string', self::MONEY],
            'cash_difference_note' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
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

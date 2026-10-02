<?php

namespace App\Http\Requests\Api\V1\DayClose;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters of GET /restaurants/{restaurant}/day-closes (CARTA 9.1A):
 * business_date range, who closed, with/without incidents.
 */
class IndexDayClosesRequest extends FormRequest
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
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'closed_by' => ['sometimes', 'integer'],
            'has_incidents' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }
}

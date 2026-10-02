<?php

namespace App\Http\Requests\Api\V1\DayClose;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /restaurants/{restaurant}/day-close/preview (CARTA 9.1A). The only
 * input is an optional opening_float, used to preview expected_cash when
 * the opening float source is "required".
 */
class PreviewDayCloseRequest extends FormRequest
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
        return ['opening_float' => ['sometimes', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/']];
    }
}

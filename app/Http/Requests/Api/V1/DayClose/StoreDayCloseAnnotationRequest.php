<?php

namespace App\Http\Requests\Api\V1\DayClose;

use App\Support\DayClose\DayCloseFormat;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of POST /day-closes/{dayClose}/annotations (CARTA 9.1A): plain
 * text, up to 2000 characters, never blank once control characters are
 * stripped.
 */
class StoreDayCloseAnnotationRequest extends FormRequest
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
        return ['body' => ['required', 'string', 'max:2000']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isEmpty() && DayCloseFormat::plainText($this->input('body')) === null) {
                $validator->errors()->add('body', 'The body field is required.');
            }
        });
    }
}

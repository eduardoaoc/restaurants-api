<?php

namespace App\Http\Requests\Api\V1\Table;

use Illuminate\Foundation\Http\FormRequest;

class OpenTableRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via TablePolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'guest_count' => ['required', 'integer', 'min:1'],
        ];
    }
}

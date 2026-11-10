<?php

namespace App\Http\Requests\Api\V1\TableSessions;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGuestCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'guest_count' => ['required', 'integer', 'min:1', 'max:100'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ];
    }
}

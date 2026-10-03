<?php

namespace App\Http\Requests\Api\V1\TableSessions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of POST /table-sessions/{tableSession}/void (CARTA 9.1A). The
 * emptiness rules are VoidEmptyTableSessionAction's job; authorization is
 * TableSessionPolicy::void, called by the controller.
 */
class VoidTableSessionRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\TableSessions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates only the shape of a transfer request — that target_table_id is
 * a real table. Whether it's a VALID target (same restaurant, not the
 * origin table, not already occupied) is TransferTableSessionAction's job,
 * not this Request's — same split as AssignWaiterRequest (Bloco 2).
 * Authorization is handled by the controller via
 * TableSessionPolicy::transfer.
 */
class TransferTableSessionRequest extends FormRequest
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
            'target_table_id' => ['required', 'integer', Rule::exists('tables', 'id')],
        ];
    }
}

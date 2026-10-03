<?php

namespace App\Http\Requests\Api\V1\TableSessions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates only the shape of an assign-waiter request — that user_id is a
 * real user. Whether that user is actually ELIGIBLE to be this session's
 * waiter is WaiterAssignmentEligibility's job (invoked from
 * AssignWaiterAction), not this Request's — see the Bloco 2 report.
 * Authorization is handled by the controller via
 * TableSessionPolicy::assignWaiter.
 */
class AssignWaiterRequest extends FormRequest
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
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ];
    }
}

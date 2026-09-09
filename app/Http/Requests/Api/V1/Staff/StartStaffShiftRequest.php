<?php

namespace App\Http\Requests\Api\V1\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates only the shape of a start-shift request — that user_id is a
 * real user. Whether that user is actually ELIGIBLE for a shift at this
 * Restaurant is StaffShiftEligibility's job (invoked from
 * StartStaffShiftAction), not this Request's. Authorization is handled by
 * the controller via StaffShiftPolicy::start.
 */
class StartStaffShiftRequest extends FormRequest
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

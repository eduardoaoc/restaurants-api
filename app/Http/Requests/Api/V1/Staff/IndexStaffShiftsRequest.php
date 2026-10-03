<?php

namespace App\Http\Requests\Api\V1\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the staff shifts list query filters' shape only — matches
 * IndexAuditLogRequest's split (shape here, period range validity via
 * StaffShiftPeriodResolver -> 422 INVALID_STAFF_SHIFT_PERIOD, not here).
 *
 * `active` accepts the common query-string boolean spellings explicitly
 * (Laravel's `boolean` rule only accepts 1/0/true/false as native types —
 * a query string always arrives as "true"/"false"/"1"/"0", so the rule is
 * spelled out here); the controller reads it back with
 * Request::boolean(), which already normalizes all of these correctly.
 */
class IndexStaffShiftsRequest extends FormRequest
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
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'active' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

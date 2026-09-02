<?php

namespace App\Http\Requests\Api\V1\Staff;

use Illuminate\Foundation\Http\FormRequest;

class StoreStaffReviewRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via StaffPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * organization_id/restaurant_id/staff_user_id/reviewer_user_id are
     * never accepted here — they are always derived server-side by the
     * controller/action.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

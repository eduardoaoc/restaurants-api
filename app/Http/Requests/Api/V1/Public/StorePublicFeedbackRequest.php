<?php

namespace App\Http\Requests\Api\V1\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the shape of a public feedback submission only. Whether the
 * feedback_token resolves, whether the session is paid, and duplicate
 * detection are SubmitPublicFeedbackAction's job (404/409 there, not 422
 * here).
 */
class StorePublicFeedbackRequest extends FormRequest
{
    /**
     * Public endpoint: no authenticated user is required.
     */
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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'wait_time_rating' => ['required', 'integer', 'min:1', 'max:5'],
            'food_rating' => ['required', 'integer', 'min:1', 'max:5'],
            'service_rating' => ['required', 'integer', 'min:1', 'max:5'],
            'overall_rating' => ['required', 'integer', 'min:1', 'max:5'],
            'experience_comment' => ['nullable', 'string', 'max:1000'],
            'improvement_comment' => ['nullable', 'string', 'max:1000'],
            'contact' => ['nullable', 'string', 'max:150'],
        ];
    }
}

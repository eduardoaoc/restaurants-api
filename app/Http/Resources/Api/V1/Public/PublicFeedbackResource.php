<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\CustomerFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Confirmation echoed back to the customer who just submitted (or
 * replayed) their own feedback — safe to include what they themselves
 * just typed, unlike the staff-facing CustomerFeedbackResource which is
 * gated by view_customer_feedback.
 *
 * @mixin CustomerFeedback
 */
class PublicFeedbackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'wait_time_rating' => $this->wait_time_rating,
            'food_rating' => $this->food_rating,
            'service_rating' => $this->service_rating,
            'overall_rating' => $this->overall_rating,
            'experience_comment' => $this->experience_comment,
            'improvement_comment' => $this->improvement_comment,
            'contact' => $this->contact,
            'submitted_at' => $this->submitted_at,
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CustomerFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full customer feedback detail — every field, including PII
 * (first_name/last_name/contact) and free-text comments. Gated by
 * view_customer_feedback (owner/manager only) — see CustomerFeedbackPolicy.
 * NEVER reuse this resource for the waiter-facing summary endpoint.
 *
 * @mixin CustomerFeedback
 */
class CustomerFeedbackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant' => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ],
            'table_session' => [
                'id' => $this->tableSession->id,
                'table' => [
                    'id' => $this->tableSession->table->id,
                    'name' => $this->tableSession->table->name,
                    'number' => $this->tableSession->table->number,
                ],
                'opened_at' => $this->tableSession->opened_at,
                'closed_at' => $this->tableSession->closed_at,
            ],
            'waiter' => $this->waiter ? [
                'id' => $this->waiter->id,
                'name' => $this->waiter->name,
            ] : null,
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

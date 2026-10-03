<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Aggregate-only projection of a waiter's customer feedback — built from a
 * plain COUNT/AVG query (see CustomerFeedbackSummaryService), never from
 * individual CustomerFeedback rows. NEVER add first_name/last_name/
 * contact/comments/any individual feedback here — that is exactly the PII
 * this endpoint exists to keep away from the waiter (Passo 3.5 §12/§15).
 *
 * @mixin object{feedbackCount: int, averageOverall: ?float, averageService: ?float, averageWaitTime: ?float, averageFood: ?float}
 */
class CustomerFeedbackSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'feedback_count' => $this->feedbackCount,
            'average_overall' => $this->averageOverall,
            'average_service' => $this->averageService,
            'average_wait_time' => $this->averageWaitTime,
            'average_food' => $this->averageFood,
        ];
    }
}

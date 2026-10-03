<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CustomerFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal listing row (Passo 3.5 §11): date, customer name, table, the
 * four ratings, and the related waiter if any — no comments/contact. Use
 * CustomerFeedbackResource for the full detail view.
 *
 * @mixin CustomerFeedback
 */
class CustomerFeedbackListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'submitted_at' => $this->submitted_at,
            'customer_name' => trim("{$this->first_name} {$this->last_name}"),
            'table' => [
                'id' => $this->tableSession->table->id,
                'name' => $this->tableSession->table->name,
                'number' => $this->tableSession->table->number,
            ],
            'overall_rating' => $this->overall_rating,
            'food_rating' => $this->food_rating,
            'service_rating' => $this->service_rating,
            'wait_time_rating' => $this->wait_time_rating,
            'waiter' => $this->waiter ? [
                'id' => $this->waiter->id,
                'name' => $this->waiter->name,
            ] : null,
        ];
    }
}

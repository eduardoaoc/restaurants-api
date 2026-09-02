<?php

namespace App\Http\Resources\Api\V1\Staff;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff member's operational metrics plus their separate rating summary
 * for one period. Deliberately excludes review comments/reviewer identity —
 * that detail is only ever exposed through StaffReviewResource, gated
 * behind manage_staff_reviews, never here.
 */
class StaffPerformanceResource extends JsonResource
{
    /**
     * @param  array{from: string, to: string}  $period
     * @param  array{tables_served: int, orders_created: int, orders_served: int, customer_orders_approved: int, table_requests_handled: int, sessions_closed: int}  $metrics
     * @param  array{average: ?string, review_count: int}  $rating
     */
    public function __construct(
        private readonly User $staff,
        private readonly ?Restaurant $restaurant,
        private readonly string $scope,
        private readonly array $period,
        private readonly array $metrics,
        private readonly array $rating,
    ) {
        parent::__construct($staff);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'staff' => [
                'id' => $this->staff->id,
                'name' => $this->staff->name,
                'restaurant' => $this->restaurant ? [
                    'id' => $this->restaurant->id,
                    'name' => $this->restaurant->name,
                ] : null,
            ],
            'scope' => $this->scope,
            'period' => [
                'from' => $this->period['from'],
                'to' => $this->period['to'],
            ],
            'metrics' => $this->metrics,
            'rating' => [
                'average' => $this->rating['average'],
                'review_count' => $this->rating['review_count'],
            ],
        ];
    }
}

<?php

namespace App\Support\Feedback;

use App\Models\CustomerFeedback;

/**
 * Computes a waiter's aggregate feedback numbers via a single COUNT/AVG
 * query — deliberately never loads/returns individual CustomerFeedback
 * rows, so there is no code path in this class that could leak PII
 * (first_name/last_name/contact/comments) to a waiter-facing endpoint
 * (Passo 3.5 §12/§13).
 */
class CustomerFeedbackSummaryResolver
{
    /**
     * @param  array<int, int>|null  $restaurantIds  Restricts to these restaurants; null means "every restaurant of the organization" (RestaurantScope semantics).
     */
    public static function forWaiter(int $waiterId, ?array $restaurantIds = null): CustomerFeedbackSummary
    {
        $query = CustomerFeedback::query()->where('waiter_id', $waiterId);

        if ($restaurantIds !== null) {
            $query->whereIn('restaurant_id', $restaurantIds);
        }

        $row = $query->selectRaw(
            'COUNT(*) as feedback_count, '
            .'AVG(overall_rating) as average_overall, '
            .'AVG(service_rating) as average_service, '
            .'AVG(wait_time_rating) as average_wait_time, '
            .'AVG(food_rating) as average_food'
        )->first();

        $count = (int) $row->feedback_count;

        return new CustomerFeedbackSummary(
            feedbackCount: $count,
            averageOverall: $count > 0 ? round((float) $row->average_overall, 2) : null,
            averageService: $count > 0 ? round((float) $row->average_service, 2) : null,
            averageWaitTime: $count > 0 ? round((float) $row->average_wait_time, 2) : null,
            averageFood: $count > 0 ? round((float) $row->average_food, 2) : null,
        );
    }
}

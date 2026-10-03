<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The restaurant operational dashboard for one half-open period. Never
 * mixes in StaffReview ratings (see StaffPerformance for that) or AuditLog
 * counts — every number here comes from Orders/PaymentRecords/
 * TableSessions/TableRequests via RestaurantDashboardService.
 */
class RestaurantDashboardResource extends JsonResource
{
    /**
     * @param  array{from: string, to: string}  $period
     * @param  array{
     *     sales: array{total: string, average_ticket: string, sessions_with_payments: int},
     *     orders: array{created: int, served: int, cancelled: int, customer_qr: int, staff_created: int},
     *     tables: array{sessions_opened: int, sessions_closed: int, current_active: int},
     *     payments: array{total_records: int, by_method: array<string, array{count: int, amount: string}>},
     *     requests: array{call_waiter: int, request_bill: int, completed: int},
     *     staff: array{top_by_orders_served: array<int, array{staff: array{id: int, name: string}, orders_served: int}>},
     * }  $summary
     */
    public function __construct(
        private readonly Restaurant $restaurant,
        private readonly array $period,
        private readonly array $summary,
    ) {
        parent::__construct($restaurant);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'restaurant' => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ],
            'period' => $this->period,
            'sales' => $this->summary['sales'],
            'orders' => $this->summary['orders'],
            'tables' => $this->summary['tables'],
            'payments' => $this->summary['payments'],
            'requests' => $this->summary['requests'],
            'staff' => $this->summary['staff'],
        ];
    }
}

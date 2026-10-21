<?php

namespace App\Events\Realtime;

/**
 * The operational day was opened or closed (after COMMIT, like every
 * RealtimeEvent). Invalidation only, on the existing private restaurant
 * channel — no opening float or any other financial value: clients refetch
 * GET /restaurants/{id}/daily-operation through their existing reads.
 */
class DailyOperationChanged extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly string $dailyOperationId,
        public readonly string $status,
        public readonly string $businessDate,
    ) {
        parent::__construct($restaurantId);
    }

    public function broadcastAs(): string
    {
        return 'daily_operation.changed';
    }

    protected function payload(): array
    {
        return [
            'daily_operation_id' => $this->dailyOperationId,
            'status' => $this->status,
            'business_date' => $this->businessDate,
        ];
    }
}

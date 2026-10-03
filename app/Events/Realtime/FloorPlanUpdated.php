<?php

namespace App\Events\Realtime;

/**
 * Dispatched by UpdateFloorPlanLayoutAction — one event per bulk save,
 * never one per table. table_ids is included only because a single save
 * is already capped to a manageable batch size by the request itself; a
 * frontend that needs anything beyond "these tables moved" refetches the
 * floor plan/live snapshot (see docs/realtime.md).
 */
class FloorPlanUpdated extends RealtimeEvent
{
    /**
     * @param  array<int, int>  $tableIds
     */
    public function __construct(
        int $restaurantId,
        public readonly int $tablesUpdatedCount,
        public readonly array $tableIds,
    ) {
        parent::__construct($restaurantId);
    }

    public function broadcastAs(): string
    {
        return 'floor_plan.updated';
    }

    protected function payload(): array
    {
        return [
            'tables_updated_count' => $this->tablesUpdatedCount,
            'table_ids' => $this->tableIds,
        ];
    }
}

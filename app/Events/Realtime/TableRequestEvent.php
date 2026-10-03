<?php

namespace App\Events\Realtime;

/**
 * Shared payload shape for the two TableRequest events (created/
 * acknowledged) — strictly customer-originated requests (call_waiter,
 * request_bill), never mixed with the separate WaiterCall domain.
 */
abstract class TableRequestEvent extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $tableId,
        public readonly int $tableSessionId,
        public readonly int $tableRequestId,
        public readonly string $type,
        public readonly string $status,
    ) {
        parent::__construct($restaurantId);
    }

    protected function payload(): array
    {
        return [
            'table_id' => $this->tableId,
            'table_session_id' => $this->tableSessionId,
            'table_request_id' => $this->tableRequestId,
            'type' => $this->type,
            'status' => $this->status,
        ];
    }
}

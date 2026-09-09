<?php

namespace App\Events\Realtime;

use Illuminate\Support\Carbon;

/**
 * Dispatched by StartStaffShiftAction after the shift is committed.
 */
class StaffShiftStarted extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $staffShiftId,
        public readonly int $userId,
        public readonly Carbon $startedAt,
    ) {
        parent::__construct($restaurantId);
    }

    public function broadcastAs(): string
    {
        return 'staff.shift.started';
    }

    protected function payload(): array
    {
        return [
            'staff_shift_id' => $this->staffShiftId,
            'user_id' => $this->userId,
            'started_at' => $this->startedAt->toISOString(),
        ];
    }
}

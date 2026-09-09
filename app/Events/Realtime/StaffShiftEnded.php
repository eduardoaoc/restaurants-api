<?php

namespace App\Events\Realtime;

use Illuminate\Support\Carbon;

/**
 * Dispatched by EndStaffShiftAction after the shift is committed ended.
 */
class StaffShiftEnded extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $staffShiftId,
        public readonly int $userId,
        public readonly Carbon $endedAt,
    ) {
        parent::__construct($restaurantId);
    }

    public function broadcastAs(): string
    {
        return 'staff.shift.ended';
    }

    protected function payload(): array
    {
        return [
            'staff_shift_id' => $this->staffShiftId,
            'user_id' => $this->userId,
            'ended_at' => $this->endedAt->toISOString(),
        ];
    }
}

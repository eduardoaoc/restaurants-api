<?php

namespace Tests\Feature\Realtime;

use App\Events\Realtime\StaffShiftEnded;
use App\Events\Realtime\StaffShiftStarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 7 — realtime events for StartStaffShiftAction/EndStaffShiftAction.
 */
class StaffShiftEventsTest extends TestCase
{
    use InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_starting_a_shift_dispatches_staff_shift_started(): void
    {
        Event::fake([StaffShiftStarted::class]);

        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $shift = $this->startShift($restaurant, $waiter, $waiter);

        Event::assertDispatched(StaffShiftStarted::class, function (StaffShiftStarted $event) use ($restaurant, $shift, $waiter) {
            return $event->restaurantId === $restaurant->id
                && $event->staffShiftId === $shift->id
                && $event->userId === $waiter->id
                && $event->broadcastAs() === 'staff.shift.started';
        });
    }

    public function test_ending_a_shift_dispatches_staff_shift_ended(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $shift = $this->startShift($restaurant, $waiter, $waiter);

        Event::fake([StaffShiftEnded::class]);

        $this->endShift($shift, $waiter);

        Event::assertDispatched(StaffShiftEnded::class, function (StaffShiftEnded $event) use ($restaurant, $shift, $waiter) {
            return $event->restaurantId === $restaurant->id
                && $event->staffShiftId === $shift->id
                && $event->userId === $waiter->id
                && $event->broadcastAs() === 'staff.shift.ended';
        });
    }
}

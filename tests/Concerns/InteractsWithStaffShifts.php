<?php

namespace Tests\Concerns;

use App\Actions\Staff\EndStaffShiftAction;
use App\Actions\Staff\StartStaffShiftAction;
use App\Models\Restaurant;
use App\Models\StaffShift;
use App\Models\User;

/**
 * StaffShift deliberately has no factory, same as TableSession/Order: its
 * rows must satisfy the eligibility invariant (membership/status/role) a
 * blind factory can't safely fake. These helpers go through the real
 * Actions instead, exactly like InteractsWithOrders does for TableSession.
 */
trait InteractsWithStaffShifts
{
    protected function startShift(Restaurant $restaurant, User $candidate, User $actor): StaffShift
    {
        return app(StartStaffShiftAction::class)->execute($restaurant, $candidate, $actor);
    }

    protected function endShift(StaffShift $shift, User $actor): StaffShift
    {
        return app(EndStaffShiftAction::class)->execute($shift, $actor);
    }
}

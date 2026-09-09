<?php

namespace App\Actions\Staff;

use App\Events\Realtime\StaffShiftStarted;
use App\Exceptions\Staff\StaffShiftConflictException;
use App\Exceptions\Staff\StaffShiftIneligibleException;
use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\StaffShift;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Staff\StaffShiftEligibility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Starts a new operational presence for a User at a Restaurant. The
 * application-level checks (eligibility, then "already active") guard the
 * common path with clear 422/409s; the partial unique index on
 * staff_shifts (user_id, restaurant_id where ended_at IS NULL) is the real
 * safety net against a race between two concurrent "start" requests for
 * the same user+restaurant — exactly like OpenTableAction.
 */
class StartStaffShiftAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Restaurant $restaurant, User $candidate, User $actor): StaffShift
    {
        return DB::transaction(function () use ($restaurant, $candidate, $actor) {
            $organization = $restaurant->organization;

            $ineligibilityReason = StaffShiftEligibility::ineligibilityReason($candidate, $organization, $restaurant);

            if ($ineligibilityReason !== null) {
                throw new StaffShiftIneligibleException($ineligibilityReason);
            }

            if (StaffShift::query()->where('restaurant_id', $restaurant->id)->where('user_id', $candidate->id)->whereNull('ended_at')->exists()) {
                throw new StaffShiftConflictException('This user already has an active shift at this restaurant.');
            }

            try {
                $shift = StaffShift::query()->create([
                    'restaurant_id' => $restaurant->id,
                    'user_id' => $candidate->id,
                    'started_at' => now(),
                    'started_by_user_id' => $actor->id,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw new StaffShiftConflictException('This user already has an active shift at this restaurant.', previous: $e);
            }

            $this->auditLogger->log(
                organizationId: $organization->id,
                restaurantId: $restaurant->id,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: AuditLog::EVENT_STAFF_SHIFT_STARTED,
                resourceType: AuditLog::RESOURCE_STAFF_SHIFT,
                resourceId: $shift->id,
                metadata: [
                    'staff_shift_id' => $shift->id,
                    'restaurant_id' => $restaurant->id,
                    'user_id' => $candidate->id,
                    'started_at' => $shift->started_at,
                ],
            );

            StaffShiftStarted::dispatch($restaurant->id, $shift->id, $candidate->id, $shift->started_at);

            return $shift;
        });
    }
}

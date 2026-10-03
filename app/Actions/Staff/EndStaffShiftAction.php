<?php

namespace App\Actions\Staff;

use App\Events\Realtime\StaffShiftEnded;
use App\Exceptions\Staff\StaffShiftConflictException;
use App\Models\AuditLog;
use App\Models\StaffShift;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Ends an active StaffShift. Locks the shift row (lockForUpdate) and
 * rechecks it is still active against freshly-read data, exactly like
 * CloseTableAction. Ending an already-ended shift is a 409 — NOT
 * idempotent, deliberately consistent with CloseTableAction's own
 * TableSessionClosedException on a repeat close (see the Bloco 3 report).
 */
class EndStaffShiftAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(StaffShift $shift, User $actor): StaffShift
    {
        return DB::transaction(function () use ($shift, $actor) {
            $locked = StaffShift::query()->whereKey($shift->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActive()) {
                throw new StaffShiftConflictException('This shift has already ended.');
            }

            $locked->update([
                'ended_at' => now(),
                'ended_by_user_id' => $actor->id,
            ]);

            $this->auditLogger->log(
                organizationId: $locked->restaurant->organization_id,
                restaurantId: $locked->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: AuditLog::EVENT_STAFF_SHIFT_ENDED,
                resourceType: AuditLog::RESOURCE_STAFF_SHIFT,
                resourceId: $locked->id,
                metadata: [
                    'staff_shift_id' => $locked->id,
                    'restaurant_id' => $locked->restaurant_id,
                    'user_id' => $locked->user_id,
                    'started_at' => $locked->started_at,
                    'ended_at' => $locked->ended_at,
                ],
            );

            StaffShiftEnded::dispatch($locked->restaurant_id, $locked->id, $locked->user_id, $locked->ended_at);

            return $locked->fresh();
        });
    }
}

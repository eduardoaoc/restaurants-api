<?php

namespace App\Actions\TableRequests;

use App\Exceptions\TableRequests\TableRequestStateConflictException;
use App\Models\AuditLog;
use App\Models\TableRequest;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Drives the table-request state machine:
 *
 *   pending -> acknowledged -> completed
 *   pending -> cancelled
 *   acknowledged -> cancelled
 *
 * Same shape as TransitionOrderStatusAction: one shared, parameterized
 * transition instead of three near-identical implementations, each row
 * locked (lockForUpdate) and its status rechecked inside the transaction
 * so two concurrent transitions on the same request can't both succeed —
 * the first to commit wins, the second gets a 409.
 *
 * Does not consult the request's TableSession at all: once created, a
 * table request's lifecycle is independent of whether its session is
 * still open (same principle as Orders — see report).
 */
class TransitionTableRequestStatusAction
{
    /**
     * @var array<string, string>
     */
    private const EVENTS = [
        TableRequest::STATUS_ACKNOWLEDGED => AuditLog::EVENT_TABLE_REQUEST_ACKNOWLEDGED,
        TableRequest::STATUS_COMPLETED => AuditLog::EVENT_TABLE_REQUEST_COMPLETED,
        TableRequest::STATUS_CANCELLED => AuditLog::EVENT_TABLE_REQUEST_CANCELLED,
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function acknowledge(TableRequest $request, User $actor): TableRequest
    {
        return $this->transition($request, [TableRequest::STATUS_PENDING], TableRequest::STATUS_ACKNOWLEDGED, 'acknowledged', $actor);
    }

    public function complete(TableRequest $request, User $actor): TableRequest
    {
        return $this->transition($request, [TableRequest::STATUS_ACKNOWLEDGED], TableRequest::STATUS_COMPLETED, 'completed', $actor);
    }

    public function cancel(TableRequest $request, User $actor): TableRequest
    {
        return $this->transition(
            $request,
            [TableRequest::STATUS_PENDING, TableRequest::STATUS_ACKNOWLEDGED],
            TableRequest::STATUS_CANCELLED,
            'cancelled',
            $actor,
        );
    }

    /**
     * @param  array<int, string>  $expectedFrom
     */
    private function transition(TableRequest $request, array $expectedFrom, string $to, string $auditFieldPrefix, User $actor): TableRequest
    {
        return DB::transaction(function () use ($request, $expectedFrom, $to, $auditFieldPrefix, $actor) {
            $locked = TableRequest::query()->whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || ! in_array($locked->status, $expectedFrom, true)) {
                throw new TableRequestStateConflictException("This table request cannot transition to '{$to}'.");
            }

            $previousStatus = $locked->status;

            $locked->update([
                'status' => $to,
                "{$auditFieldPrefix}_by_user_id" => $actor->id,
                "{$auditFieldPrefix}_at" => now(),
            ]);

            $fresh = $locked->fresh(['restaurant', 'table']);

            $this->auditLogger->log(
                organizationId: $fresh->restaurant->organization_id,
                restaurantId: $fresh->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: self::EVENTS[$to],
                resourceType: AuditLog::RESOURCE_TABLE_REQUEST,
                resourceId: $fresh->id,
                metadata: ['previous_status' => $previousStatus, 'new_status' => $to, 'type' => $fresh->type],
            );

            return $fresh;
        });
    }
}

<?php

namespace Tests\Concerns;

use App\Actions\TableRequests\CreatePublicTableRequestAction;
use App\Actions\TableRequests\TransitionTableRequestStatusAction;
use App\Models\Table;
use App\Models\TableRequest;
use App\Models\User;
use InvalidArgumentException;

/**
 * TableRequest deliberately has no factory, same as Order/TableSession:
 * its rows must satisfy cross-model invariants (restaurant/table/session
 * coherence) that a blind factory can't safely fake. Every helper here
 * goes through the real Actions instead.
 */
trait InteractsWithTableRequests
{
    /**
     * Create a table request via the public flow, exactly as the public
     * endpoint would.
     */
    protected function createTableRequest(Table $table, string $type, ?string $note = null): TableRequest
    {
        return app(CreatePublicTableRequestAction::class)->execute($table->public_token, $type, $note);
    }

    /**
     * Drive a `pending` table request through the state machine up to (and
     * including) $targetStatus, via the real
     * TransitionTableRequestStatusAction — the same code the endpoints use.
     */
    protected function advanceTableRequestTo(TableRequest $tableRequest, string $targetStatus, User $actor): TableRequest
    {
        $action = app(TransitionTableRequestStatusAction::class);

        return match ($targetStatus) {
            TableRequest::STATUS_ACKNOWLEDGED => $action->acknowledge($tableRequest, $actor),
            TableRequest::STATUS_COMPLETED => $action->complete($action->acknowledge($tableRequest, $actor), $actor),
            TableRequest::STATUS_CANCELLED => $action->cancel($tableRequest, $actor),
            default => throw new InvalidArgumentException("Unsupported target status: {$targetStatus}"),
        };
    }
}

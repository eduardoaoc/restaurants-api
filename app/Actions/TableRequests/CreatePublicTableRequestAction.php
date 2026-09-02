<?php

namespace App\Actions\TableRequests;

use App\Actions\Public\ResolvePublicTableAction;
use App\Exceptions\Orders\TableSessionNotActiveException;
use App\Exceptions\TableRequests\TableRequestAlreadyOpenException;
use App\Models\TableRequest;
use App\Models\TableSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a table request (call_waiter/request_bill) from the public QR
 * surface. Resolves the table exactly like GET /public/tables/{token} does
 * (public_token -> Table -> Restaurant -> Organization; never
 * TenantContext) and, like order creation, requires an active
 * TableSession — the session is re-resolved and re-locked here rather than
 * trusted from an earlier GET, since it may have closed in between.
 *
 * Reuses TableSessionNotActiveException from the Orders exceptions
 * namespace rather than declaring a duplicate: it's the exact same public
 * contract (409 TABLE_SESSION_NOT_ACTIVE), just triggered from a different
 * action.
 */
class CreatePublicTableRequestAction
{
    public function __construct(private readonly ResolvePublicTableAction $resolvePublicTable) {}

    public function execute(string $publicToken, string $type, ?string $note): TableRequest
    {
        $table = $this->resolvePublicTable->execute($publicToken);
        $session = $table->activeSession;

        if (! $session) {
            throw new TableSessionNotActiveException;
        }

        return DB::transaction(function () use ($table, $session, $type, $note) {
            $lockedSession = TableSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $lockedSession || ! $lockedSession->isActive()) {
                throw new TableSessionNotActiveException;
            }

            $hasOpenRequestOfType = TableRequest::query()
                ->where('table_session_id', $lockedSession->id)
                ->where('type', $type)
                ->whereIn('status', TableRequest::openStatuses())
                ->exists();

            if ($hasOpenRequestOfType) {
                throw new TableRequestAlreadyOpenException;
            }

            try {
                return TableRequest::query()->create([
                    'restaurant_id' => $table->restaurant_id,
                    'table_id' => $table->id,
                    'table_session_id' => $lockedSession->id,
                    'type' => $type,
                    'status' => TableRequest::STATUS_PENDING,
                    'note' => $note,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Lost a race against a concurrent duplicate submit — the
                // partial unique index is the real guard; the exists()
                // check above is only a friendlier fast path.
                throw new TableRequestAlreadyOpenException(previous: $e);
            }
        });
    }
}

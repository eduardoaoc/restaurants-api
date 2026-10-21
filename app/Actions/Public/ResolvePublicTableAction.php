<?php

namespace App\Actions\Public;

use App\Exceptions\Orders\TableSessionNotActiveException;
use App\Exceptions\Public\PublicTableNotFoundException;
use App\Exceptions\Public\PublicVisitInvalidException;
use App\Models\AuditLog;
use App\Models\Table;
use App\Models\TableSession;

class ResolvePublicTableAction
{
    /**
     * Resolve a public token to a servable Table, loaded with its active
     * restaurant, that restaurant's menu, and its active session (if any).
     *
     * The context (Restaurant/Organization) is derived exclusively from the
     * token itself — never from an id supplied by the caller.
     */
    public function execute(string $publicToken, ?string $visitToken = null): Table
    {
        $table = Table::query()
            ->where('public_token', $publicToken)
            ->where('status', 'active')
            ->whereHas('restaurant', fn ($query) => $query->where('status', 'active'))
            ->with(['restaurant.menu', 'restaurant.settings', 'activeSession'])
            ->first();

        if (! $table) {
            throw new PublicTableNotFoundException;
        }

        if ($visitToken === '' && $table->activeSession === null) {
            throw new TableSessionNotActiveException;
        }

        if ($visitToken !== null) {
            // Pin reads AND writes to the original visit, including after
            // payment/close. Never silently follow this table's next session.
            $visit = $visitToken === '' ? null : TableSession::query()
                ->where('restaurant_id', $table->restaurant_id)
                ->where('feedback_token', $visitToken)
                ->where(function ($query) use ($table) {
                    $query->where('table_id', $table->id)
                        ->orWhereIn('id', AuditLog::query()->select('resource_id')
                            ->where('restaurant_id', $table->restaurant_id)
                            ->where('resource_type', AuditLog::RESOURCE_TABLE_SESSION)
                            ->where('event', AuditLog::EVENT_TABLE_SESSION_TRANSFERRED)
                            ->where('metadata->from_table_id', $table->id));
                })->first();
            if (! $visit) {
                throw new PublicVisitInvalidException;
            }
            if ($visit->table_id !== $table->id) {
                // Only an audited transfer can keep the original QR bound to
                // this visit. Its next occupation remains a different session.
                $table = Table::query()->whereKey($visit->table_id)
                    ->where('restaurant_id', $table->restaurant_id)->where('status', 'active')
                    ->with(['restaurant.menu', 'restaurant.settings'])->first();
                if (! $table) {
                    throw new PublicTableNotFoundException;
                }
            }
            $table->setRelation('activeSession', $visit);
        }

        return $table;
    }
}

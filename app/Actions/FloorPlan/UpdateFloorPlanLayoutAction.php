<?php

namespace App\Actions\FloorPlan;

use App\Events\Realtime\FloorPlanUpdated;
use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-saves floor plan layout changes for many tables in one atomic
 * transaction. Ownership (table belongs to $restaurant, any zone_id
 * belongs to $restaurant too) is already guaranteed by
 * UpdateFloorPlanLayoutRequest before this ever runs — the
 * where('restaurant_id', ...) + lockForUpdate below is defense in depth,
 * not the primary gate.
 *
 * Writes only each table's own layout columns — never touches
 * TableSession/Order/PaymentRecord/TableRequest history, which is
 * unrelated to where a table is drawn on the map.
 *
 * Records exactly ONE aggregated audit event for the whole batch (never
 * one per table — see the Bloco 1 report, "not audited coordinate by
 * coordinate").
 */
class UpdateFloorPlanLayoutAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<int, array<string, mixed>>  $tables
     */
    public function execute(Restaurant $restaurant, array $tables, User $actor): int
    {
        return DB::transaction(function () use ($restaurant, $tables, $actor) {
            $updatedIds = [];

            foreach ($tables as $tableData) {
                $table = Table::query()
                    ->where('restaurant_id', $restaurant->id)
                    ->lockForUpdate()
                    ->findOrFail($tableData['id']);

                $changes = collect($tableData)->except('id')->toArray();

                if ($changes !== []) {
                    $table->update($changes);
                }

                $updatedIds[] = $table->id;
            }

            $this->auditLogger->log(
                organizationId: $restaurant->organization_id,
                restaurantId: $restaurant->id,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: AuditLog::EVENT_FLOOR_PLAN_LAYOUT_UPDATED,
                resourceType: AuditLog::RESOURCE_RESTAURANT,
                resourceId: $restaurant->id,
                metadata: [
                    'tables_updated_count' => count($updatedIds),
                    'table_ids' => $updatedIds,
                ],
            );

            FloorPlanUpdated::dispatch($restaurant->id, count($updatedIds), $updatedIds);

            return count($updatedIds);
        });
    }
}

<?php

namespace App\Actions\FloorLayout;

use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantFloorLayout;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a restaurant's whole 3D floor layout with an already-validated
 * document, atomically (single upsert + one audit row in one transaction).
 * Last write wins — there is no optimistic-concurrency token in this phase.
 */
class ReplaceRestaurantFloorLayoutAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{version: int, floor: array<string, mixed>, tables: array<int, array<string, mixed>>}  $document
     */
    public function execute(Restaurant $restaurant, array $document, User $actor): RestaurantFloorLayout
    {
        return DB::transaction(function () use ($restaurant, $document, $actor) {
            $layout = RestaurantFloorLayout::query()
                ->where('restaurant_id', $restaurant->id)
                ->lockForUpdate()
                ->first() ?? new RestaurantFloorLayout(['restaurant_id' => $restaurant->id]);

            $layout->schema_version = $document['version'];
            $layout->layout = ['floor' => $document['floor'], 'tables' => array_values($document['tables'])];
            $layout->save();

            $this->auditLogger->log(
                organizationId: $restaurant->organization_id,
                restaurantId: $restaurant->id,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: AuditLog::EVENT_FLOOR_LAYOUT_REPLACED,
                resourceType: AuditLog::RESOURCE_RESTAURANT,
                resourceId: $restaurant->id,
                metadata: ['tables_count' => count($document['tables'])],
            );

            return $layout;
        });
    }
}

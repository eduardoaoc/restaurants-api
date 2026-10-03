<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The aggregated, editor-ready floor plan of a restaurant: every floor,
 * each with its zones, each with its tables — plus any table not yet
 * assigned to a zone, so the editor can show it for placement. Reuses
 * TableResource for tables (no duplicated table response logic).
 *
 * $restaurant must have floors.zones.tables eager-loaded by the caller.
 */
class FloorPlanResource extends JsonResource
{
    public function __construct(
        private readonly Restaurant $restaurant,
        private readonly Collection $unassignedTables,
    ) {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'restaurant_id' => $this->restaurant->id,
            'floors' => $this->restaurant->floors
                ->sortBy('sort_order')
                ->values()
                ->map(fn ($floor) => [
                    'id' => $floor->id,
                    'name' => $floor->name,
                    'sort_order' => $floor->sort_order,
                    'is_active' => $floor->is_active,
                    'zones' => $floor->zones
                        ->sortBy('sort_order')
                        ->values()
                        ->map(fn ($zone) => [
                            'id' => $zone->id,
                            'name' => $zone->name,
                            'sort_order' => $zone->sort_order,
                            'is_active' => $zone->is_active,
                            'tables' => TableResource::collection($zone->tables),
                        ]),
                ]),
            'unassigned_tables' => TableResource::collection($this->unassignedTables),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RestaurantFloorLayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RestaurantFloorLayout
 */
class RestaurantFloorLayoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->schema_version,
            'floor' => $this->layout['floor'],
            'entrance' => $this->layout['entrance'] ?? null,
            'tables' => $this->layout['tables'],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

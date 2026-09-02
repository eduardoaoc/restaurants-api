<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ModifierOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ModifierOption
 */
class ModifierOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'modifier_group_id' => $this->modifier_group_id,
            'internal_name' => $this->internal_name,
            'price_delta' => $this->price_delta,
            'available' => $this->available,
            'sort_order' => $this->sort_order,
            'status' => $this->status,
            'translations' => $this->whenLoaded('translations', fn () => $this->translations->map(fn ($translation) => [
                'locale' => $translation->locale,
                'name' => $translation->name,
                'description' => $translation->description,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

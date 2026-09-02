<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ModifierGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ModifierGroup
 */
class ModifierGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_product_id' => $this->restaurant_product_id,
            'internal_name' => $this->internal_name,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'required' => $this->required,
            'sort_order' => $this->sort_order,
            'status' => $this->status,
            'translations' => $this->whenLoaded('translations', fn () => $this->translations->map(fn ($translation) => [
                'locale' => $translation->locale,
                'name' => $translation->name,
                'description' => $translation->description,
            ])),
            'options' => $this->whenLoaded('options', fn () => ModifierOptionResource::collection($this->options)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

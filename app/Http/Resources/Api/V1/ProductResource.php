<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'sku' => $this->sku,
            'internal_name' => $this->internal_name,
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

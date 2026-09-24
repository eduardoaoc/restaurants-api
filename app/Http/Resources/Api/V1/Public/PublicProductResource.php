<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Http\Resources\Api\V1\ProductMediaResource;
use App\Models\ProductTranslation;
use App\Models\RestaurantProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public projection of a RestaurantProduct: price, availability and
 * modifiers always come from the operational unit (RestaurantProduct),
 * never from the shared Product catalog entry.
 *
 * @mixin RestaurantProduct
 */
class PublicProductResource extends JsonResource
{
    /**
     * @param  array<int, PublicModifierGroupResource>  $modifierGroups
     */
    public function __construct(
        RestaurantProduct $resource,
        private readonly ProductTranslation $translation,
        private readonly array $modifierGroups,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'restaurant_product_id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->translation->name,
            'description' => $this->translation->description,
            'price' => (string) $this->price,
            'allergens' => $this->resource->product->allergens,
            'nutrition' => $this->resource->product->nutritionPayload(),
            'media' => [
                'image' => optional($this->resource->product->mediaImage(), fn ($media) => new ProductMediaResource($media)),
                'video' => optional($this->resource->product->mediaVideo(), fn ($media) => new ProductMediaResource($media)),
            ],
            'modifier_groups' => $this->modifierGroups,
        ];
    }
}

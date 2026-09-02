<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\Category;
use App\Models\CategoryTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class PublicCategoryResource extends JsonResource
{
    /**
     * @param  array<int, PublicProductResource>  $products
     */
    public function __construct(
        Category $resource,
        private readonly CategoryTranslation $translation,
        private readonly array $products,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->translation->name,
            'description' => $this->translation->description,
            'products' => $this->products,
        ];
    }
}

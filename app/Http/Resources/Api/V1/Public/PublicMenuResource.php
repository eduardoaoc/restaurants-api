<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Menu
 */
class PublicMenuResource extends JsonResource
{
    /**
     * @param  array<int, PublicCategoryResource>  $categories
     */
    public function __construct(Menu $resource, private readonly array $categories)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'categories' => $this->categories,
        ];
    }
}

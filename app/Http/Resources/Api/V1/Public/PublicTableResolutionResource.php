<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Table
 */
class PublicTableResolutionResource extends JsonResource
{
    public function __construct(Table $resource, private readonly bool $menuAvailable)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'restaurant' => new PublicRestaurantResource($this->restaurant),
            'table' => new PublicTableResource($this->resource),
            'session' => new PublicSessionStateResource($this->activeSession),
            'menu' => [
                'available' => $this->menuAvailable,
            ],
        ];
    }
}

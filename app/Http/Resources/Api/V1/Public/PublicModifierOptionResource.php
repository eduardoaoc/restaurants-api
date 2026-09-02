<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\ModifierOption;
use App\Models\ModifierOptionTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ModifierOption
 */
class PublicModifierOptionResource extends JsonResource
{
    public function __construct(
        ModifierOption $resource,
        private readonly ModifierOptionTranslation $translation,
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
            'name' => $this->translation->name,
            'description' => $this->translation->description,
            'price_delta' => (string) $this->price_delta,
        ];
    }
}

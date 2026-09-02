<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\ModifierGroup;
use App\Models\ModifierGroupTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ModifierGroup
 */
class PublicModifierGroupResource extends JsonResource
{
    /**
     * @param  array<int, PublicModifierOptionResource>  $options
     */
    public function __construct(
        ModifierGroup $resource,
        private readonly ModifierGroupTranslation $translation,
        private readonly array $options,
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
            'required' => $this->required,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'options' => $this->options,
        ];
    }
}

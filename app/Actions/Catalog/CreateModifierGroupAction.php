<?php

namespace App\Actions\Catalog;

use App\Models\ModifierGroup;
use App\Models\RestaurantProduct;
use Illuminate\Support\Facades\DB;

class CreateModifierGroupAction
{
    /**
     * @param  array{internal_name: string, min_select?: int, max_select: int, required?: bool, sort_order?: int, status?: string, translations: array<int, array{locale: string, name: string, description?: ?string}>}  $data
     */
    public function execute(RestaurantProduct $restaurantProduct, array $data): ModifierGroup
    {
        return DB::transaction(function () use ($restaurantProduct, $data) {
            $modifierGroup = $restaurantProduct->modifierGroups()->create([
                'internal_name' => $data['internal_name'],
                'min_select' => $data['min_select'] ?? 0,
                'max_select' => $data['max_select'],
                'required' => $data['required'] ?? false,
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => $data['status'] ?? 'active',
            ]);

            foreach ($data['translations'] as $translation) {
                $modifierGroup->translations()->create($translation);
            }

            return $modifierGroup->load('translations');
        });
    }
}

<?php

namespace App\Actions\Catalog;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use Illuminate\Support\Facades\DB;

class CreateModifierOptionAction
{
    /**
     * @param  array{internal_name: string, price_delta?: float|string, available?: bool, sort_order?: int, status?: string, translations: array<int, array{locale: string, name: string, description?: ?string}>}  $data
     */
    public function execute(ModifierGroup $modifierGroup, array $data): ModifierOption
    {
        return DB::transaction(function () use ($modifierGroup, $data) {
            $modifierOption = $modifierGroup->options()->create([
                'internal_name' => $data['internal_name'],
                'price_delta' => $data['price_delta'] ?? 0,
                'available' => $data['available'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => $data['status'] ?? 'active',
            ]);

            foreach ($data['translations'] as $translation) {
                $modifierOption->translations()->create($translation);
            }

            return $modifierOption->load('translations');
        });
    }
}

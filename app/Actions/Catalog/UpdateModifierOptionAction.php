<?php

namespace App\Actions\Catalog;

use App\Models\ModifierOption;
use Illuminate\Support\Facades\DB;

class UpdateModifierOptionAction
{
    /**
     * Updates the option's own fields and, if given, upserts translations
     * per locale — locales not present in the payload are left untouched.
     *
     * @param  array{internal_name?: string, price_delta?: float|string, available?: bool, sort_order?: int, status?: string, translations?: array<int, array{locale: string, name: string, description?: ?string}>}  $data
     */
    public function execute(ModifierOption $modifierOption, array $data): ModifierOption
    {
        return DB::transaction(function () use ($modifierOption, $data) {
            $modifierOption->fill(array_intersect_key(
                $data,
                array_flip(['internal_name', 'price_delta', 'available', 'sort_order', 'status'])
            ));
            $modifierOption->save();

            foreach ($data['translations'] ?? [] as $translation) {
                $modifierOption->translations()->updateOrCreate(
                    ['locale' => $translation['locale']],
                    ['name' => $translation['name'], 'description' => $translation['description'] ?? null],
                );
            }

            return $modifierOption->load('translations');
        });
    }
}

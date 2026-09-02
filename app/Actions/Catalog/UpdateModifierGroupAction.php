<?php

namespace App\Actions\Catalog;

use App\Models\ModifierGroup;
use Illuminate\Support\Facades\DB;

class UpdateModifierGroupAction
{
    /**
     * Updates the group's own fields and, if given, upserts translations
     * per locale — locales not present in the payload are left untouched.
     *
     * @param  array{internal_name?: string, min_select?: int, max_select?: int, required?: bool, sort_order?: int, status?: string, translations?: array<int, array{locale: string, name: string, description?: ?string}>}  $data
     */
    public function execute(ModifierGroup $modifierGroup, array $data): ModifierGroup
    {
        return DB::transaction(function () use ($modifierGroup, $data) {
            $modifierGroup->fill(array_intersect_key(
                $data,
                array_flip(['internal_name', 'min_select', 'max_select', 'required', 'sort_order', 'status'])
            ));
            $modifierGroup->save();

            foreach ($data['translations'] ?? [] as $translation) {
                $modifierGroup->translations()->updateOrCreate(
                    ['locale' => $translation['locale']],
                    ['name' => $translation['name'], 'description' => $translation['description'] ?? null],
                );
            }

            return $modifierGroup->load('translations');
        });
    }
}

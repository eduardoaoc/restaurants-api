<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

class UpdateCategoryAction
{
    /**
     * Updates the category's own fields and, if given, upserts translations
     * per locale — locales not present in the payload are left untouched.
     *
     * @param  array{slug?: string, sort_order?: int, status?: string, translations?: array<int, array{locale: string, name: string, description?: ?string}>}  $data
     */
    public function execute(Category $category, array $data): Category
    {
        return DB::transaction(function () use ($category, $data) {
            $category->fill(array_intersect_key($data, array_flip(['slug', 'sort_order', 'status'])));
            $category->save();

            foreach ($data['translations'] ?? [] as $translation) {
                $category->translations()->updateOrCreate(
                    ['locale' => $translation['locale']],
                    ['name' => $translation['name'], 'description' => $translation['description'] ?? null],
                );
            }

            return $category->load('translations');
        });
    }
}

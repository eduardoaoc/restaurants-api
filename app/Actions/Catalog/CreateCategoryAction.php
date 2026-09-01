<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use App\Models\Menu;
use Illuminate\Support\Facades\DB;

class CreateCategoryAction
{
    /**
     * @param  array{slug: string, sort_order?: int, status?: string, translations: array<int, array{locale: string, name: string, description?: ?string}>}  $data
     */
    public function execute(Menu $menu, array $data): Category
    {
        return DB::transaction(function () use ($menu, $data) {
            $category = $menu->categories()->create([
                'slug' => $data['slug'],
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => $data['status'] ?? 'active',
            ]);

            foreach ($data['translations'] as $translation) {
                $category->translations()->create($translation);
            }

            return $category->load('translations');
        });
    }
}

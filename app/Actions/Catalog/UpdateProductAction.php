<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class UpdateProductAction
{
    /**
     * Updates the product's own fields and, if given, upserts translations
     * per locale — locales not present in the payload are left untouched.
     * `allergens`/`nutrition` follow the same "omitted = preserve" rule;
     * only an explicitly present key replaces the current declaration
     * (including replacing it with `[]`/`null`).
     *
     * @param  array{sku?: ?string, internal_name?: string, status?: string, translations?: array<int, array{locale: string, name: string, description?: ?string}>, allergens?: ?array<int, string>, nutrition?: ?array{calories_kcal?: ?int, protein_g?: ?float, carbohydrates_g?: ?float, fat_g?: ?float, salt_g?: ?float}}  $data
     */
    public function execute(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->fill(array_intersect_key($data, array_flip(['sku', 'internal_name', 'status'])));

            if (array_key_exists('allergens', $data)) {
                $product->allergens = $data['allergens'];
            }

            if (array_key_exists('nutrition', $data)) {
                $nutrition = $data['nutrition'] ?? [];
                $product->calories_kcal = $nutrition['calories_kcal'] ?? null;
                $product->protein_g = $nutrition['protein_g'] ?? null;
                $product->carbohydrates_g = $nutrition['carbohydrates_g'] ?? null;
                $product->fat_g = $nutrition['fat_g'] ?? null;
                $product->salt_g = $nutrition['salt_g'] ?? null;
            }

            $product->save();

            foreach ($data['translations'] ?? [] as $translation) {
                $product->translations()->updateOrCreate(
                    ['locale' => $translation['locale']],
                    ['name' => $translation['name'], 'description' => $translation['description'] ?? null],
                );
            }

            return $product->load('translations');
        });
    }
}

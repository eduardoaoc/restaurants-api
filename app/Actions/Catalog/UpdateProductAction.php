<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class UpdateProductAction
{
    /**
     * Updates the product's own fields and, if given, upserts translations
     * per locale — locales not present in the payload are left untouched.
     *
     * @param  array{sku?: ?string, internal_name?: string, status?: string, translations?: array<int, array{locale: string, name: string, description?: ?string}>}  $data
     */
    public function execute(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->fill(array_intersect_key($data, array_flip(['sku', 'internal_name', 'status'])));
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

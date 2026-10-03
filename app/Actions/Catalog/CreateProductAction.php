<?php

namespace App\Actions\Catalog;

use App\Models\Organization;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CreateProductAction
{
    /**
     * @param  array{sku?: ?string, internal_name: string, status?: string, translations: array<int, array{locale: string, name: string, description?: ?string}>, allergens?: ?array<int, string>, nutrition?: ?array{calories_kcal?: ?int, protein_g?: ?float, carbohydrates_g?: ?float, fat_g?: ?float, salt_g?: ?float}}  $data
     */
    public function execute(Organization $organization, array $data): Product
    {
        return DB::transaction(function () use ($organization, $data) {
            $nutrition = $data['nutrition'] ?? [];

            $product = $organization->products()->create([
                'sku' => $data['sku'] ?? null,
                'internal_name' => $data['internal_name'],
                'status' => $data['status'] ?? 'active',
                'allergens' => $data['allergens'] ?? null,
                'calories_kcal' => $nutrition['calories_kcal'] ?? null,
                'protein_g' => $nutrition['protein_g'] ?? null,
                'carbohydrates_g' => $nutrition['carbohydrates_g'] ?? null,
                'fat_g' => $nutrition['fat_g'] ?? null,
                'salt_g' => $nutrition['salt_g'] ?? null,
            ]);

            foreach ($data['translations'] as $translation) {
                $product->translations()->create($translation);
            }

            return $product->load(['translations', 'media']);
        });
    }
}

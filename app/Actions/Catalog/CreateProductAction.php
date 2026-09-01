<?php

namespace App\Actions\Catalog;

use App\Models\Organization;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CreateProductAction
{
    /**
     * @param  array{sku?: ?string, internal_name: string, status?: string, translations: array<int, array{locale: string, name: string, description?: ?string}>}  $data
     */
    public function execute(Organization $organization, array $data): Product
    {
        return DB::transaction(function () use ($organization, $data) {
            $product = $organization->products()->create([
                'sku' => $data['sku'] ?? null,
                'internal_name' => $data['internal_name'],
                'status' => $data['status'] ?? 'active',
            ]);

            foreach ($data['translations'] as $translation) {
                $product->translations()->create($translation);
            }

            return $product->load('translations');
        });
    }
}

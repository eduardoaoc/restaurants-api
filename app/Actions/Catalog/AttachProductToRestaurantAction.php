<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use Illuminate\Support\Facades\DB;

class AttachProductToRestaurantAction
{
    /**
     * @param  array{price: float|string, available?: bool}  $data
     */
    public function execute(Restaurant $restaurant, Product $product, array $data): RestaurantProduct
    {
        return DB::transaction(function () use ($restaurant, $product, $data) {
            return RestaurantProduct::query()->create([
                'restaurant_id' => $restaurant->id,
                'product_id' => $product->id,
                'price' => $data['price'],
                'available' => $data['available'] ?? true,
            ]);
        });
    }
}

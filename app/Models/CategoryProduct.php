<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable(['category_id', 'restaurant_product_id', 'sort_order'])]
class CategoryProduct extends Model
{
    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<RestaurantProduct, $this>
     */
    public function restaurantProduct(): BelongsTo
    {
        return $this->belongsTo(RestaurantProduct::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $categoryProduct) {
            $category = Category::query()->with('menu')->find($categoryProduct->category_id);
            $restaurantProduct = RestaurantProduct::query()->find($categoryProduct->restaurant_product_id);

            if (! $category || ! $restaurantProduct) {
                return;
            }

            if ($category->menu->restaurant_id !== $restaurantProduct->restaurant_id) {
                throw new InvalidArgumentException('The restaurant product must belong to the same restaurant as the category.');
            }
        });
    }
}

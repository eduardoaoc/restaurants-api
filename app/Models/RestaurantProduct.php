<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['restaurant_id', 'product_id', 'price', 'available'])]
class RestaurantProduct extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'available' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<CategoryProduct, $this>
     */
    public function categoryProducts(): HasMany
    {
        return $this->hasMany(CategoryProduct::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $restaurantProduct) {
            $productOrganizationId = Product::query()->whereKey($restaurantProduct->product_id)->value('organization_id');
            $restaurantOrganizationId = Restaurant::query()->whereKey($restaurantProduct->restaurant_id)->value('organization_id');

            if ($productOrganizationId !== $restaurantOrganizationId) {
                throw new InvalidArgumentException('The product does not belong to the same organization as the restaurant.');
            }
        });
    }
}

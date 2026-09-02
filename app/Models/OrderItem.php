<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'restaurant_product_id', 'product_id',
    'product_name_snapshot', 'product_description_snapshot', 'unit_price_snapshot',
    'quantity', 'modifiers_unit_total_snapshot', 'unit_total_snapshot', 'line_total_snapshot',
    'customer_note',
])]
class OrderItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_snapshot' => 'decimal:2',
            'quantity' => 'integer',
            'modifiers_unit_total_snapshot' => 'decimal:2',
            'unit_total_snapshot' => 'decimal:2',
            'line_total_snapshot' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<RestaurantProduct, $this>
     */
    public function restaurantProduct(): BelongsTo
    {
        return $this->belongsTo(RestaurantProduct::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<OrderItemModifier, $this>
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }
}

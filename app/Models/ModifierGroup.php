<?php

namespace App\Models;

use Database\Factories\ModifierGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['restaurant_product_id', 'internal_name', 'min_select', 'max_select', 'required', 'sort_order', 'status'])]
class ModifierGroup extends Model
{
    /** @use HasFactory<ModifierGroupFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<RestaurantProduct, $this>
     */
    public function restaurantProduct(): BelongsTo
    {
        return $this->belongsTo(RestaurantProduct::class);
    }

    /**
     * @return HasMany<ModifierGroupTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ModifierGroupTranslation::class);
    }

    /**
     * @return HasMany<ModifierOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\RestaurantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'name', 'slug', 'status', 'timezone', 'default_locale'])]
class Restaurant extends Model
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    /**
     * The organization this restaurant belongs to.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The operational users linked to this restaurant.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'restaurant_users')
            ->withPivot('sub_id')
            ->withTimestamps();
    }

    /**
     * The role assignments granted within this restaurant.
     *
     * @return HasMany<UserRole, $this>
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * The tables that belong to this restaurant.
     *
     * @return HasMany<Table, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }

    /**
     * The single menu of this restaurant, if it has been created yet.
     *
     * @return HasOne<Menu, $this>
     */
    public function menu(): HasOne
    {
        return $this->hasOne(Menu::class);
    }

    /**
     * The catalog products that have been priced/enabled for this restaurant.
     *
     * @return HasMany<RestaurantProduct, $this>
     */
    public function restaurantProducts(): HasMany
    {
        return $this->hasMany(RestaurantProduct::class);
    }
}

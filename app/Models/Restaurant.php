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

#[Fillable(['organization_id', 'name', 'slug', 'status'])]
class Restaurant extends Model
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    // Tenant self-service value (see StoreRestaurantRequest/
    // UpdateRestaurantRequest) — unrelated to platform suspension below.
    public const STATUS_INACTIVE = 'inactive';

    // Platform-only value: never accepted by the tenant-facing Store/
    // UpdateRestaurantRequest, only settable via
    // PATCH /platform/restaurants/{restaurant}/status. See
    // RestaurantController::update, which refuses to let a tenant request
    // move status away from this value.
    public const STATUS_SUSPENDED = 'suspended';

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
     * The floors of this restaurant's floor plan (Bloco 1).
     *
     * @return HasMany<Floor, $this>
     */
    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class);
    }

    /**
     * The zones of this restaurant's floor plan, across every floor.
     *
     * @return HasMany<Zone, $this>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
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
     * This restaurant's operational configuration — always present for a
     * real Restaurant (see RestaurantSettings::createDefaultsFor()).
     *
     * @return HasOne<RestaurantSettings, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(RestaurantSettings::class);
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

    /**
     * All historical call_waiter/request_bill requests for this restaurant.
     *
     * @return HasMany<TableRequest, $this>
     */
    public function tableRequests(): HasMany
    {
        return $this->hasMany(TableRequest::class);
    }

    /**
     * All historical manual payment records for this restaurant.
     *
     * @return HasMany<PaymentRecord, $this>
     */
    public function paymentRecords(): HasMany
    {
        return $this->hasMany(PaymentRecord::class);
    }
}

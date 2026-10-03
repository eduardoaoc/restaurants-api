<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'status', 'plan', 'subscription_status'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    // Tenant self-service value (see UpdateOrganizationRequest) — an owner
    // may still toggle their own org active/inactive; unrelated to
    // platform suspension below.
    public const STATUS_INACTIVE = 'inactive';

    // Platform-only value: never accepted by the tenant-facing
    // UpdateOrganizationRequest, only settable via
    // PATCH /platform/organizations/{organization}/status. See
    // ResolveTenant, which blocks every tenant route while suspended —
    // including the owner's own PATCH /organization.
    public const STATUS_SUSPENDED = 'suspended';

    public const PLAN_FREE = 'free';

    public const PLAN_STARTER = 'starter';

    public const PLAN_PRO = 'pro';

    public const PLAN_ENTERPRISE = 'enterprise';

    /**
     * @var array<int, string>
     */
    public const PLANS = [self::PLAN_FREE, self::PLAN_STARTER, self::PLAN_PRO, self::PLAN_ENTERPRISE];

    public const SUBSCRIPTION_STATUS_ACTIVE = 'active';

    public const SUBSCRIPTION_STATUS_TRIALING = 'trialing';

    public const SUBSCRIPTION_STATUS_PAST_DUE = 'past_due';

    public const SUBSCRIPTION_STATUS_CANCELED = 'canceled';

    /**
     * @var array<int, string>
     */
    public const SUBSCRIPTION_STATUSES = [
        self::SUBSCRIPTION_STATUS_ACTIVE,
        self::SUBSCRIPTION_STATUS_TRIALING,
        self::SUBSCRIPTION_STATUS_PAST_DUE,
        self::SUBSCRIPTION_STATUS_CANCELED,
    ];

    /**
     * The restaurants that belong to this organization.
     *
     * @return HasMany<Restaurant, $this>
     */
    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }

    /**
     * The users that belong to this organization.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')
            ->withPivot('status')
            ->withTimestamps();
    }

    /**
     * The role assignments granted within this organization.
     *
     * @return HasMany<UserRole, $this>
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * The product catalog of this organization, reusable across restaurants.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}

<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_SUSPENDED];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The organizations this user belongs to.
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
            ->withTimestamps();
    }

    /**
     * The restaurants this user is linked to as an operational employee.
     *
     * @return BelongsToMany<Restaurant, $this>
     */
    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'restaurant_users')
            ->withPivot('sub_id')
            ->withTimestamps();
    }

    /**
     * The contextual role assignments held by this user.
     *
     * @return HasMany<UserRole, $this>
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * The roles held by this user, across all contexts.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['organization_id', 'restaurant_id'])
            ->withTimestamps();
    }

    /**
     * The internal reviews received by this user as an operational staff
     * member (this user as the reviewed party, not the reviewer).
     *
     * @return HasMany<StaffReview, $this>
     */
    public function staffReviews(): HasMany
    {
        return $this->hasMany(StaffReview::class, 'staff_user_id');
    }

    /**
     * Determine whether this user holds a role within the given organization
     * that grants the given permission slug.
     */
    public function hasPermission(string $permissionSlug, Organization $organization): bool
    {
        return $this->userRoles()
            ->where('organization_id', $organization->id)
            ->whereHas('role.permissions', function ($query) use ($permissionSlug) {
                $query->where('slug', $permissionSlug);
            })
            ->exists();
    }

    /**
     * The platform role assignments held by this user — entirely
     * independent of organization_id/restaurant_id. See
     * PlatformRoleAssignment and the Bloco 0 report.
     *
     * @return HasMany<PlatformRoleAssignment, $this>
     */
    public function platformRoleAssignments(): HasMany
    {
        return $this->hasMany(PlatformRoleAssignment::class);
    }

    /**
     * The platform roles held by this user, across the whole platform
     * (never scoped to an organization or restaurant).
     *
     * @return BelongsToMany<PlatformRole, $this>
     */
    public function platformRoles(): BelongsToMany
    {
        return $this->belongsToMany(PlatformRole::class, 'platform_role_assignments')
            ->withTimestamps();
    }

    /**
     * True when this user holds ANY platform role — i.e. is a platform
     * admin of some kind (super_admin today; support/billing_admin/etc.
     * in the future). Used by the `platform_admin` middleware as the
     * coarse gate for the whole /platform namespace; individual abilities
     * are still checked per-permission via hasPlatformPermission().
     */
    public function isPlatformAdmin(): bool
    {
        return $this->platformRoleAssignments()->exists();
    }

    /**
     * Determine whether this user holds a platform role that grants the
     * given platform permission slug. Entirely separate from
     * hasPermission() above — a platform permission is never satisfied by
     * a tenant role, and vice versa.
     */
    public function hasPlatformPermission(string $permissionSlug): bool
    {
        return $this->platformRoleAssignments()
            ->whereHas('platformRole.permissions', function ($query) use ($permissionSlug) {
                $query->where('slug', $permissionSlug);
            })
            ->exists();
    }

    /**
     * True when a platform admin has suspended this account. A suspended
     * user cannot authenticate (see AuthController::login) and has no
     * active session (see PlatformUserController::updateStatus, which
     * deletes their sessions rows on suspension).
     */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }
}

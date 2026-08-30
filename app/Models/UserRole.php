<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable(['user_id', 'role_id', 'organization_id', 'restaurant_id'])]
class UserRole extends Model
{
    /**
     * The user this role is assigned to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The role being assigned.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The organization context of this role assignment.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The restaurant context of this role assignment, when scoped to one.
     *
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $userRole) {
            if ($userRole->restaurant_id === null) {
                return;
            }

            $restaurantOrganizationId = Restaurant::query()
                ->whereKey($userRole->restaurant_id)
                ->value('organization_id');

            if ($restaurantOrganizationId !== $userRole->organization_id) {
                throw new InvalidArgumentException(
                    'The restaurant does not belong to the given organization.'
                );
            }
        });
    }
}

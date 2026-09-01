<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;

class RestaurantPolicy
{
    /**
     * Any member of the organization may list its restaurants.
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists();
    }

    /**
     * Any member of the restaurant's organization may view it.
     */
    public function view(User $user, Restaurant $restaurant): bool
    {
        return $user->organizations()->whereKey($restaurant->organization_id)->exists();
    }

    /**
     * Only users holding the manage_restaurants permission may create restaurants.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission('manage_restaurants', $organization);
    }

    /**
     * Only users holding the manage_restaurants permission may update restaurants.
     */
    public function update(User $user, Restaurant $restaurant): bool
    {
        return $user->hasPermission('manage_restaurants', $restaurant->organization);
    }
}

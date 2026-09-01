<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\User;

class RestaurantProductPolicy
{
    public function create(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant->organization);
    }

    public function update(User $user, RestaurantProduct $restaurantProduct): bool
    {
        return $this->canManage($user, $restaurantProduct->restaurant->organization);
    }

    private function canManage(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists()
            && $user->hasPermission('manage_products', $organization);
    }
}

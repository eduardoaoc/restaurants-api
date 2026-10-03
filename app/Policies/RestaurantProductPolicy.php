<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\User;
use App\Policies\Concerns\AuthorizesRestaurantScopedCatalog;

class RestaurantProductPolicy
{
    use AuthorizesRestaurantScopedCatalog;

    public function viewAny(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant);
    }

    public function create(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant);
    }

    public function update(User $user, RestaurantProduct $restaurantProduct): bool
    {
        return $this->canManage($user, $restaurantProduct->restaurant);
    }

    private function canManage(User $user, Restaurant $restaurant): bool
    {
        return $this->userCanAccessRestaurantWithAnyPermission($user, $restaurant, ['manage_products']);
    }
}

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
        return $this->userCanAccessRestaurantWithAnyPermission($user, $restaurant, ['manage_products', 'manage_product_availability']);
    }

    public function create(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant);
    }

    public function update(User $user, RestaurantProduct $restaurantProduct): bool
    {
        return $this->canManage($user, $restaurantProduct->restaurant);
    }

    public function updateAvailability(User $user, RestaurantProduct $restaurantProduct): bool
    {
        return $this->userCanAccessRestaurantWithAnyPermission($user, $restaurantProduct->restaurant, ['manage_products', 'manage_product_availability']);
    }

    private function canManage(User $user, Restaurant $restaurant): bool
    {
        return $this->userCanAccessRestaurantWithAnyPermission($user, $restaurant, ['manage_products']);
    }
}

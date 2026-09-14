<?php

namespace App\Policies;

use App\Models\ModifierGroup;
use App\Models\RestaurantProduct;
use App\Models\User;
use App\Policies\Concerns\AuthorizesRestaurantScopedCatalog;

class ModifierGroupPolicy
{
    use AuthorizesRestaurantScopedCatalog;

    /**
     * Read-only: a staff member with create_orders needs to see a
     * product's modifier groups (required/optional, min/max) to build a
     * valid order payload — see CategoryPolicy for the same Passo 3.2
     * rationale. create()/update() below stay manage_products-only.
     */
    public function viewAny(User $user, RestaurantProduct $restaurantProduct): bool
    {
        return $this->canRead($user, $restaurantProduct);
    }

    public function view(User $user, ModifierGroup $modifierGroup): bool
    {
        return $this->canRead($user, $modifierGroup->restaurantProduct);
    }

    public function create(User $user, RestaurantProduct $restaurantProduct): bool
    {
        return $this->canManage($user, $restaurantProduct);
    }

    public function update(User $user, ModifierGroup $modifierGroup): bool
    {
        return $this->canManage($user, $modifierGroup->restaurantProduct);
    }

    private function canRead(User $user, RestaurantProduct $restaurantProduct): bool
    {
        return $this->userCanAccessRestaurantWithAnyPermission(
            $user, $restaurantProduct->restaurant, ['manage_products', 'create_orders']
        );
    }

    private function canManage(User $user, RestaurantProduct $restaurantProduct): bool
    {
        return $this->userCanAccessRestaurantWithAnyPermission(
            $user, $restaurantProduct->restaurant, ['manage_products']
        );
    }
}

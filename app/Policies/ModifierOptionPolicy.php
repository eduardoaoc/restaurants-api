<?php

namespace App\Policies;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\RestaurantProduct;
use App\Models\User;
use App\Policies\Concerns\AuthorizesRestaurantScopedCatalog;

class ModifierOptionPolicy
{
    use AuthorizesRestaurantScopedCatalog;

    /**
     * Read-only: a staff member with create_orders needs to see each
     * option's name/price_delta/availability to build a valid order
     * payload — see CategoryPolicy for the same Passo 3.2 rationale.
     * create()/update() below stay manage_products-only.
     */
    public function viewAny(User $user, ModifierGroup $modifierGroup): bool
    {
        return $this->canRead($user, $modifierGroup->restaurantProduct);
    }

    public function view(User $user, ModifierOption $modifierOption): bool
    {
        return $this->canRead($user, $modifierOption->modifierGroup->restaurantProduct);
    }

    public function create(User $user, ModifierGroup $modifierGroup): bool
    {
        return $this->canManage($user, $modifierGroup->restaurantProduct);
    }

    public function update(User $user, ModifierOption $modifierOption): bool
    {
        return $this->canManage($user, $modifierOption->modifierGroup->restaurantProduct);
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

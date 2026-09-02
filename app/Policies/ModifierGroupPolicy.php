<?php

namespace App\Policies;

use App\Models\ModifierGroup;
use App\Models\Organization;
use App\Models\RestaurantProduct;
use App\Models\User;

class ModifierGroupPolicy
{
    public function viewAny(User $user, RestaurantProduct $restaurantProduct): bool
    {
        return $this->canManage($user, $restaurantProduct->restaurant->organization);
    }

    public function view(User $user, ModifierGroup $modifierGroup): bool
    {
        return $this->canManage($user, $modifierGroup->restaurantProduct->restaurant->organization);
    }

    public function create(User $user, RestaurantProduct $restaurantProduct): bool
    {
        return $this->canManage($user, $restaurantProduct->restaurant->organization);
    }

    public function update(User $user, ModifierGroup $modifierGroup): bool
    {
        return $this->canManage($user, $modifierGroup->restaurantProduct->restaurant->organization);
    }

    private function canManage(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists()
            && $user->hasPermission('manage_products', $organization);
    }
}

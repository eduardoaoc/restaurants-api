<?php

namespace App\Policies;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Organization;
use App\Models\User;

class ModifierOptionPolicy
{
    public function viewAny(User $user, ModifierGroup $modifierGroup): bool
    {
        return $this->canManage($user, $modifierGroup->restaurantProduct->restaurant->organization);
    }

    public function view(User $user, ModifierOption $modifierOption): bool
    {
        return $this->canManage($user, $modifierOption->modifierGroup->restaurantProduct->restaurant->organization);
    }

    public function create(User $user, ModifierGroup $modifierGroup): bool
    {
        return $this->canManage($user, $modifierGroup->restaurantProduct->restaurant->organization);
    }

    public function update(User $user, ModifierOption $modifierOption): bool
    {
        return $this->canManage($user, $modifierOption->modifierGroup->restaurantProduct->restaurant->organization);
    }

    private function canManage(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists()
            && $user->hasPermission('manage_products', $organization);
    }
}

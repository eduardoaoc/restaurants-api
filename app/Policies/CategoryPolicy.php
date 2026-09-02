<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant->organization);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->canManage($user, $category->menu->restaurant->organization);
    }

    public function create(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant->organization);
    }

    /**
     * Also used to authorize managing the category's products
     * (POST/PATCH /categories/{category}/products...).
     */
    public function update(User $user, Category $category): bool
    {
        return $this->canManage($user, $category->menu->restaurant->organization);
    }

    private function canManage(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists()
            && $user->hasPermission('manage_menu', $organization);
    }
}

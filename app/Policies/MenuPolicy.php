<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\User;
use App\Policies\Concerns\AuthorizesRestaurantScopedCatalog;

/**
 * Authorizes menu management. All abilities are authorized against the
 * Restaurant (not the Menu itself) so a restaurant with no menu yet can
 * still be authorized for the "create the menu" flow.
 */
class MenuPolicy
{
    use AuthorizesRestaurantScopedCatalog;

    public function view(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant);
    }

    public function create(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant);
    }

    public function update(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant);
    }

    private function canManage(User $user, Restaurant $restaurant): bool
    {
        return $this->userCanAccessRestaurantWithAnyPermission($user, $restaurant, ['manage_menu']);
    }
}

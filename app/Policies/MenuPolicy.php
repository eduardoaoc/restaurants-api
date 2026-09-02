<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;

/**
 * Authorizes menu management. All abilities are authorized against the
 * Restaurant (not the Menu itself) so a restaurant with no menu yet can
 * still be authorized for the "create the menu" flow.
 */
class MenuPolicy
{
    public function view(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant->organization);
    }

    public function create(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant->organization);
    }

    public function update(User $user, Restaurant $restaurant): bool
    {
        return $this->canManage($user, $restaurant->organization);
    }

    private function canManage(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists()
            && $user->hasPermission('manage_menu', $organization);
    }
}

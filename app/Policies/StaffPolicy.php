<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/**
 * Authorizes management of operational staff (manager/waiter/kitchen/cashier)
 * within the active organization. Every ability requires the acting user to
 * belong to the organization and to hold the manage_users permission there —
 * there is no role-name shortcut; owner and manager only pass because their
 * seeded role grants manage_users.
 */
class StaffPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->canManageUsers($user, $organization);
    }

    public function view(User $user, User $staff, Organization $organization): bool
    {
        return $this->canManageUsers($user, $organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->canManageUsers($user, $organization);
    }

    public function update(User $user, User $staff, Organization $organization): bool
    {
        return $this->canManageUsers($user, $organization);
    }

    private function canManageUsers(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists()
            && $user->hasPermission('manage_users', $organization);
    }
}

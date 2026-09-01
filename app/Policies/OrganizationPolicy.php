<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Any member of the organization may view it.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists();
    }

    /**
     * Only users holding the manage_organization permission may update it.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->hasPermission('manage_organization', $organization);
    }
}

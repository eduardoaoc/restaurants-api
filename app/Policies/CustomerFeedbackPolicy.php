<?php

namespace App\Policies;

use App\Models\CustomerFeedback;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;

/**
 * Authorizes viewing customer feedback (Passo 3.5) — a domain deliberately
 * separate from StaffReview (see CustomerFeedback's docblock). Every
 * ability requires view_customer_feedback (owner/manager only — see
 * RolePermissionSeeder) plus RestaurantScope::canAccessRestaurant(): a
 * waiter never gets this permission at all (their own aggregate is served
 * by a separate summary endpoint with no permission gate beyond
 * authentication, mirroring /me/performance).
 */
class CustomerFeedbackPolicy
{
    public function viewAny(User $user, Organization $organization, Restaurant $restaurant): bool
    {
        return $this->canView($user, $organization, $restaurant);
    }

    public function view(User $user, CustomerFeedback $feedback): bool
    {
        return $this->canView($user, $feedback->restaurant->organization, $feedback->restaurant);
    }

    private function canView(User $user, Organization $organization, Restaurant $restaurant): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists()
            && $user->hasPermission('view_customer_feedback', $organization)
            && RestaurantScope::canAccessRestaurant($user, $restaurant);
    }
}

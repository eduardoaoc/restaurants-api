<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers platform-level authorization as named Gate abilities, kept
 * fully separate from AppServiceProvider's Gate::policy() bindings.
 *
 * Deliberately NOT Gate::policy(User::class, ...) / Gate::policy(
 * Organization::class, ...) / Gate::policy(Restaurant::class, ...): those
 * classes are already bound to StaffPolicy/OrganizationPolicy/
 * RestaurantPolicy for tenant authorization, and binding a second policy
 * to the same class is not how Laravel policies work anyway — it would
 * either collide or silently shadow the tenant policy. Named abilities
 * called explicitly (`$this->authorize('platform.users.manage')`, no
 * model argument) avoid that collision entirely and make it structurally
 * impossible for a platform check to be reached from a tenant controller
 * by accident, or vice versa.
 *
 * Every ability here defers to User::hasPlatformPermission(), never to a
 * role name — identical philosophy to the tenant side (see
 * StaffPolicy/OrganizationPolicy), so a future platform role (support,
 * billing_admin, ...) with a narrower permission set is authorized
 * correctly with zero changes here.
 */
class PlatformAuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('platform.users.view', fn (User $user) => $user->hasPlatformPermission('view_platform_users'));
        Gate::define('platform.users.manage', fn (User $user) => $user->hasPlatformPermission('manage_platform_users'));

        Gate::define('platform.organizations.view', fn (User $user) => $user->hasPlatformPermission('view_platform_organizations'));
        Gate::define('platform.organizations.manage', fn (User $user) => $user->hasPlatformPermission('manage_platform_organizations'));

        Gate::define('platform.restaurants.view', fn (User $user) => $user->hasPlatformPermission('view_platform_restaurants'));
        Gate::define('platform.restaurants.manage', fn (User $user) => $user->hasPlatformPermission('manage_platform_restaurants'));

        Gate::define('platform.audit.view', fn (User $user) => $user->hasPlatformPermission('view_platform_audit'));
    }
}

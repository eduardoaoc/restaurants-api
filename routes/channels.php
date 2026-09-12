<?php

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| One private channel per Restaurant (Bloco 7) — never a global operations
| channel, never a cross-restaurant leak. A user may subscribe as long as
| they belong to the Restaurant's Organization AND the Restaurant is
| within their own RestaurantScope (see RestaurantScope) — exactly the
| same reachability rule RestaurantPolicy::view() already enforces for
| GET /restaurants/{restaurant}.
|
| Deliberately NOT gated behind view_operations/view_reports or any other
| dashboard permission: a waiter/kitchen/cashier who cannot open the
| administrative dashboard must still be able to receive the operational
| events relevant to the restaurant they work at (order status changes,
| table requests, ...) — opening the dashboard and receiving operational
| events are two different concerns (see the Bloco 7 report, item 11).
|
| A Platform Super Admin gets no automatic bypass here — they only pass
| this check if they also happen to be a real member of the restaurant's
| organization. A cross-tenant, cross-organization "platform operations"
| channel is explicitly out of scope for this block (item 12).
|
| This route is registered by bootstrap/app.php's withBroadcasting() call
| with the same auth stack as the rest of the tenant API (auth:sanctum +
| active_user) — see that file and docs/realtime.md for the full picture.
*/

Broadcast::channel('restaurant.{restaurantId}', function (User $user, int $restaurantId) {
    $restaurant = Restaurant::query()->find($restaurantId);

    if (! $restaurant) {
        return false;
    }

    // Parity with ResolveTenant's own suspended-organization check: the
    // broadcasting auth route intentionally does not run the `tenant`
    // middleware (there is no single "active organization" for a
    // subscribe request — the restaurant id in the channel name says
    // which one), so this is re-checked explicitly here instead.
    if ($restaurant->organization->status === Organization::STATUS_SUSPENDED) {
        return false;
    }

    // Passo 2.8B-FIX: same active-membership gate ResolveTenant applies to
    // ordinary tenant routes, re-checked here for the same reason as the
    // suspended-organization check above — a staff member deactivated in
    // THIS organization (OrganizationUser::STATUS_INACTIVE) must not be
    // able to authenticate this restaurant's channel, even while still
    // fully active in another organization.
    return $user->organizations()
        ->wherePivot('status', OrganizationUser::STATUS_ACTIVE)
        ->whereKey($restaurant->organization_id)->exists()
        && RestaurantScope::canAccessRestaurant($user, $restaurant);
});

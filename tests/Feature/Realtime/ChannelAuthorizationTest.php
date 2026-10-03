<?php

namespace Tests\Feature\Realtime;

use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 7 — private restaurant.{id} channel authorization: the exact same
 * reachability rule as RestaurantPolicy::view() (organization membership +
 * RestaurantScope), deliberately WITHOUT requiring view_operations/
 * view_reports — see routes/channels.php.
 *
 * A real Pusher/Reverb client signs the channel auth request with
 * socket_id + channel_name; the socket_id itself is never validated
 * against a live connection server-side, so any string works here.
 *
 * phpunit.xml deliberately sets BROADCAST_CONNECTION=null suite-wide (see
 * item 71/84) so no Feature test ever needs a live Reverb server — but
 * the 'null' driver's auth() is a total no-op (always 200, empty body),
 * which would make this file assert nothing real. This class alone
 * forces the 'reverb' connection (self-contained fake credentials, no
 * network I/O: constructing the Pusher SDK client and signing a channel
 * auth response are both pure/local — only an actual broadcast() call
 * would reach the network, which authorization never does) so the exact
 * routes/channels.php closure genuinely runs.
 */
class ChannelAuthorizationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);

        // routes/channels.php ran once already at application boot,
        // registering `restaurant.{restaurantId}` against whatever
        // broadcaster instance backed the (then-default) 'null'
        // connection — channel registrations live on that specific
        // broadcaster instance, not globally. Re-requiring it now, after
        // switching the default above, registers the exact same closure
        // against the 'reverb'-backed instance this test actually
        // exercises.
        require base_path('routes/channels.php');
    }

    private function authorize(int $restaurantId): TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-restaurant.{$restaurantId}",
            'socket_id' => '1234.5678',
        ]);
    }

    public function test_owner_is_authorized_for_their_restaurant_channel(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->authorize($restaurant->id)
            ->assertOk();
    }

    public function test_manager_within_restaurant_scope_is_authorized_without_any_dashboard_permission(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        // A waiter holds no view_reports permission at all, and channel
        // auth must not require view_operations either (see
        // RolePermissionSeeder — a waiter DOES hold view_operations since
        // the Passo 3.2 fix, but that's incidental here) — per item 11.
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->authorize($restaurant->id)
            ->assertOk();
    }

    public function test_staff_outside_their_restaurant_scope_is_denied(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');

        $this->actingAs($waiterA, 'web')
            ->authorize($restaurantB->id)
            ->assertForbidden();
    }

    public function test_cross_organization_user_is_denied(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();

        $this->actingAs($ownerA, 'web')
            ->authorize($restaurantB->id)
            ->assertForbidden();
    }

    public function test_guest_is_unauthenticated(): void
    {
        [, , $restaurant] = $this->createTenant();

        $this->authorize($restaurant->id)->assertUnauthorized();
    }

    public function test_nonexistent_restaurant_is_denied(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->authorize(999_999)
            ->assertForbidden();
    }

    public function test_suspended_organization_is_denied_even_for_its_own_owner(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $organization->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->actingAs($owner, 'web')
            ->authorize($restaurant->id)
            ->assertForbidden();
    }
}

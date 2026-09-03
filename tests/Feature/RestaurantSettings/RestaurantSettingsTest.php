<?php

namespace Tests\Feature\RestaurantSettings;

use App\Models\AuditLog;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Defaults, GET/PATCH happy paths, permission/scope (404 vs 403), and
 * audit behavior for RestaurantSettings.
 */
class RestaurantSettingsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    // --- Defaults --------------------------------------------------------

    public function test_new_restaurant_has_valencia_spain_defaults(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/settings")
            ->assertOk()
            ->assertJsonPath('data.settings.default_locale', 'es-ES')
            ->assertJsonPath('data.settings.enabled_locales', ['es-ES', 'ca-ES-valencia', 'en-GB'])
            ->assertJsonPath('data.settings.currency', 'EUR')
            ->assertJsonPath('data.settings.timezone', 'Europe/Madrid')
            ->assertJsonPath('data.settings.customer_ordering_enabled', true)
            ->assertJsonPath('data.settings.customer_order_requires_approval', false)
            ->assertJsonPath('data.settings.waiter_call_enabled', true)
            ->assertJsonPath('data.settings.bill_request_enabled', true)
            ->assertJsonPath('data.settings.kitchen_ticket_printing_enabled', true)
            ->assertJsonPath('data.settings.bill_receipt_printing_enabled', true);
    }

    public function test_restaurant_created_via_the_api_gets_default_settings_in_the_same_transaction(): void
    {
        [, $owner] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/restaurants', ['name' => 'New Branch', 'slug' => 'new-branch'])
            ->assertCreated();

        $restaurantId = $response->json('data.restaurant.id');

        $this->assertDatabaseHas('restaurant_settings', [
            'restaurant_id' => $restaurantId,
            'default_locale' => 'es-ES',
            'currency' => 'EUR',
        ]);
    }

    // --- Update happy path -------------------------------------------------

    public function test_owner_can_partially_update_settings(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", [
                'customer_order_requires_approval' => true,
                'default_locale' => 'en-GB',
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.customer_order_requires_approval', true)
            ->assertJsonPath('data.settings.default_locale', 'en-GB')
            // Untouched fields are preserved.
            ->assertJsonPath('data.settings.currency', 'EUR')
            ->assertJsonPath('data.settings.waiter_call_enabled', true);
    }

    public function test_organization_id_and_restaurant_id_in_body_are_ignored(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", [
                'restaurant_id' => $otherRestaurant->id,
                'organization_id' => 999999,
                'timezone' => 'Atlantic/Canary',
            ])
            ->assertOk();

        $this->assertDatabaseHas('restaurant_settings', ['restaurant_id' => $restaurant->id, 'timezone' => 'Atlantic/Canary']);
        $this->assertDatabaseMissing('restaurant_settings', ['restaurant_id' => $otherRestaurant->id, 'timezone' => 'Atlantic/Canary']);
    }

    // --- Multi-tenancy / permission (404 vs 403) --------------------------

    public function test_owner_can_manage_settings_of_any_restaurant_in_the_organization(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')->getJson("/api/v1/restaurants/{$restaurantA->id}/settings")->assertOk();
        $this->actingAs($owner, 'web')->getJson("/api/v1/restaurants/{$restaurantB->id}/settings")->assertOk();
    }

    public function test_manager_scoped_to_a_gets_404_for_restaurant_b(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/settings")
            ->assertNotFound();

        $this->actingAs($managerA, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurantB->id}/settings", ['timezone' => 'Europe/Madrid'])
            ->assertNotFound();
    }

    public function test_manager_scoped_to_own_restaurant_can_manage_its_settings(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/settings")
            ->assertOk();
    }

    public function test_waiter_without_manage_restaurants_permission_gets_403_for_own_restaurant(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/settings")
            ->assertForbidden();
    }

    public function test_restaurant_of_another_organization_is_404(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/settings")
            ->assertNotFound();
    }

    // --- Audit -------------------------------------------------------------

    public function test_a_real_change_records_restaurant_settings_updated(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['customer_order_requires_approval' => true])
            ->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_RESTAURANT_SETTINGS_UPDATED)->first();
        $this->assertNotNull($log);
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame($restaurant->id, $log->restaurant_id);
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertSame(AuditLog::RESOURCE_RESTAURANT, $log->resource_type);
        $this->assertEquals(
            ['old' => false, 'new' => true],
            $log->changes['customer_order_requires_approval'],
        );
    }

    public function test_no_op_update_records_no_audit_event(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['currency' => 'EUR'])
            ->assertOk();

        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_RESTAURANT_SETTINGS_UPDATED)->count());
    }

    public function test_forbidden_update_records_no_audit_event(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['timezone' => 'Atlantic/Canary'])
            ->assertForbidden();

        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_RESTAURANT_SETTINGS_UPDATED)->count());
    }
}

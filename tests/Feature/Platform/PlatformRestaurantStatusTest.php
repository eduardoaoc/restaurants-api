<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PlatformRestaurantStatusTest extends TestCase
{
    use InteractsWithPlatform, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->seedPlatformRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_super_admin_suspends_a_restaurant(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization, , $restaurant] = $this->createTenant();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/restaurants/{$restaurant->id}/status", [
                'status' => Restaurant::STATUS_SUSPENDED,
                'reason' => 'Health inspection failure reported by local authority.',
            ])
            ->assertOk()
            ->assertJsonPath('data.restaurant.status', Restaurant::STATUS_SUSPENDED);

        $this->assertSame(Restaurant::STATUS_SUSPENDED, $restaurant->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => AuditLog::EVENT_PLATFORM_RESTAURANT_SUSPENDED,
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
            'resource_type' => AuditLog::RESOURCE_RESTAURANT,
            'resource_id' => $restaurant->id,
        ]);
    }

    /**
     * A suspended restaurant cannot be un-suspended by its own
     * organization through the ordinary tenant PATCH endpoint — only
     * through the platform endpoint.
     */
    public function test_owner_cannot_reverse_a_platform_suspension_through_the_tenant_update_endpoint(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $restaurant->update(['status' => Restaurant::STATUS_SUSPENDED]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}", ['status' => 'active'])
            ->assertForbidden();

        $this->assertSame(Restaurant::STATUS_SUSPENDED, $restaurant->fresh()->status);
    }

    /**
     * Non-status fields remain editable by the tenant even while
     * suspended — restaurant suspension is not a full tenant lockout the
     * way organization suspension is (see the Bloco 0 report's Gaps).
     */
    public function test_owner_can_still_edit_other_fields_of_a_suspended_restaurant(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $restaurant->update(['status' => Restaurant::STATUS_SUSPENDED]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}", ['name' => 'Renamed While Suspended'])
            ->assertOk();

        $this->assertSame('Renamed While Suspended', $restaurant->fresh()->name);
        $this->assertSame(Restaurant::STATUS_SUSPENDED, $restaurant->fresh()->status);
    }

    public function test_super_admin_reactivates_a_restaurant(): void
    {
        $admin = $this->createPlatformAdmin();
        [, , $restaurant] = $this->createTenant();
        $restaurant->update(['status' => Restaurant::STATUS_SUSPENDED]);

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/restaurants/{$restaurant->id}/status", [
                'status' => Restaurant::STATUS_ACTIVE,
                'reason' => 'Re-inspection passed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.restaurant.status', Restaurant::STATUS_ACTIVE);

        $this->assertDatabaseHas('audit_logs', [
            'event' => AuditLog::EVENT_PLATFORM_RESTAURANT_REACTIVATED,
            'restaurant_id' => $restaurant->id,
        ]);
    }

    public function test_reason_is_required_to_change_restaurant_status(): void
    {
        $admin = $this->createPlatformAdmin();
        [, , $restaurant] = $this->createTenant();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/restaurants/{$restaurant->id}/status", [
                'status' => Restaurant::STATUS_SUSPENDED,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_filtering_platform_restaurants_by_organization(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organizationA, , $restaurantA] = $this->createTenant();
        [$organizationB] = $this->createTenant();

        $response = $this->actingAs($admin, 'web')
            ->getJson("/api/v1/platform/restaurants?organization_id={$organizationA->id}")
            ->assertOk();

        $ids = collect($response->json('data.restaurants'))->pluck('id');

        $this->assertTrue($ids->contains($restaurantA->id));
        $this->assertCount(1, $ids);
    }
}

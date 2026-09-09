<?php

namespace Tests\Feature\Zone;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ZoneStoreTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_create_a_zone(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/zones", [
                'name' => 'Interior',
                'floor_id' => $floor->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.zone.name', 'Interior')
            ->assertJsonPath('data.zone.floor_id', $floor->id);

        $this->assertDatabaseHas('zones', ['restaurant_id' => $restaurant->id, 'floor_id' => $floor->id, 'name' => 'Interior']);
    }

    public function test_waiter_cannot_create_a_zone(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/zones", ['name' => 'Interior', 'floor_id' => $floor->id])
            ->assertForbidden();
    }

    public function test_floor_id_from_another_restaurant_is_rejected(): void
    {
        [, $owner, $restaurantA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $floorB = $this->createFloor($restaurantB);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/zones", ['name' => 'Interior', 'floor_id' => $floorB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['floor_id']);

        $this->assertDatabaseMissing('zones', ['name' => 'Interior']);
    }

    public function test_name_and_floor_id_are_required(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/zones", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'floor_id']);
    }

    public function test_creating_a_zone_under_a_restaurant_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $floorB = $this->createFloor($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/zones", ['name' => 'Pwned', 'floor_id' => $floorB->id])
            ->assertNotFound();

        $this->assertDatabaseMissing('zones', ['name' => 'Pwned']);
    }

    public function test_creating_a_zone_records_an_audit_event(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/zones", ['name' => 'Interior', 'floor_id' => $floor->id])
            ->assertCreated();

        $zoneId = $response->json('data.zone.id');

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
            'event' => AuditLog::EVENT_ZONE_CREATED,
            'resource_type' => AuditLog::RESOURCE_ZONE,
            'resource_id' => $zoneId,
        ]);
    }
}

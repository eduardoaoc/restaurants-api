<?php

namespace Tests\Feature\Floor;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class FloorUpdateTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_update_a_floor(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant, 'Ground Floor');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/floors/{$floor->id}", ['name' => 'Renamed Floor', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.floor.name', 'Renamed Floor')
            ->assertJsonPath('data.floor.is_active', false);

        $this->assertDatabaseHas('floors', ['id' => $floor->id, 'name' => 'Renamed Floor', 'is_active' => false]);
    }

    public function test_waiter_cannot_update_a_floor(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/floors/{$floor->id}", ['name' => 'Pwned'])
            ->assertForbidden();
    }

    public function test_updating_a_floor_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $floorB = $this->createFloor($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/floors/{$floorB->id}", ['name' => 'Pwned'])
            ->assertNotFound();

        $this->assertDatabaseMissing('floors', ['name' => 'Pwned']);
    }

    public function test_updating_a_floor_records_an_audit_event_with_old_and_new_values(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant, 'Ground Floor');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/floors/{$floor->id}", ['name' => 'Renamed Floor'])
            ->assertOk();

        $log = AuditLog::query()
            ->where('event', AuditLog::EVENT_FLOOR_UPDATED)
            ->where('resource_id', $floor->id)
            ->firstOrFail();

        $this->assertSame('Ground Floor', $log->changes['name']['old']);
        $this->assertSame('Renamed Floor', $log->changes['name']['new']);
    }
}

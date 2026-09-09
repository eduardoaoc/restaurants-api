<?php

namespace Tests\Feature\Table;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TableUpdateTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_update_name_number_and_status(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant, 'Old Name', 1);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/tables/{$table->id}", [
                'name' => 'New Name',
                'number' => 2,
                'status' => 'blocked',
            ])
            ->assertOk()
            ->assertJsonPath('data.table.name', 'New Name')
            ->assertJsonPath('data.table.number', 2)
            ->assertJsonPath('data.table.status', 'blocked');
    }

    public function test_kitchen_without_permission_cannot_update_a_table(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);

        $this->actingAs($kitchen, 'web')
            ->patchJson("/api/v1/tables/{$table->id}", ['name' => 'Hacked'])
            ->assertForbidden();

        $this->assertDatabaseMissing('tables', ['name' => 'Hacked']);
    }

    public function test_restaurant_id_cannot_be_changed(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        [, , $otherRestaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/tables/{$table->id}", [
                'restaurant_id' => $otherRestaurant->id,
                'name' => 'Still Mine',
            ])
            ->assertOk();

        $this->assertDatabaseHas('tables', [
            'id' => $table->id,
            'restaurant_id' => $restaurant->id,
            'name' => 'Still Mine',
        ]);
    }

    public function test_public_token_cannot_be_changed(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $originalToken = $table->public_token;

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/tables/{$table->id}", ['public_token' => 'attacker-token'])
            ->assertOk();

        $this->assertDatabaseHas('tables', [
            'id' => $table->id,
            'public_token' => $originalToken,
        ]);
    }

    public function test_owner_can_update_capacity_zone_and_layout(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);
        $zone = $this->createZone($floor);
        $table = $this->createTable($restaurant);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/tables/{$table->id}", [
                'capacity' => 6,
                'zone_id' => $zone->id,
                'layout_x' => 0.7,
                'layout_y' => 0.2,
                'layout_rotation' => 180,
            ])
            ->assertOk()
            ->assertJsonPath('data.table.capacity', 6)
            ->assertJsonPath('data.table.zone_id', $zone->id)
            ->assertJsonPath('data.table.layout.rotation', 180);
    }

    public function test_waiter_with_manage_tables_can_rename_a_table_but_not_move_it_on_the_floor_plan(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);
        $zone = $this->createZone($floor);
        $table = $this->createTable($restaurant);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/tables/{$table->id}", ['name' => 'Renamed by waiter'])
            ->assertOk();

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/tables/{$table->id}", ['zone_id' => $zone->id])
            ->assertForbidden();

        $this->assertDatabaseHas('tables', ['id' => $table->id, 'zone_id' => null]);
    }

    public function test_zone_from_another_restaurant_is_rejected(): void
    {
        [, $owner, $restaurantA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $table = $this->createTable($restaurantA);
        $floorB = $this->createFloor($restaurantB);
        $zoneB = $this->createZone($floorB);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/tables/{$table->id}", ['zone_id' => $zoneB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['zone_id']);
    }

    public function test_table_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/tables/{$tableB->id}", ['name' => 'Hacked'])
            ->assertNotFound();

        $this->assertDatabaseMissing('tables', ['name' => 'Hacked']);
    }
}

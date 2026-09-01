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

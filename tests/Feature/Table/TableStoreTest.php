<?php

namespace Tests\Feature\Table;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TableStoreTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_create_a_table(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/tables", [
                'name' => 'Mesa 12',
                'number' => 12,
            ])
            ->assertCreated()
            ->assertJsonPath('data.table.name', 'Mesa 12')
            ->assertJsonPath('data.table.number', 12)
            ->assertJsonPath('data.table.status', 'active')
            ->assertJsonPath('data.table.has_active_session', false);

        $this->assertDatabaseHas('tables', ['restaurant_id' => $restaurant->id, 'name' => 'Mesa 12']);
    }

    public function test_kitchen_without_permission_cannot_create_a_table(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/tables", ['name' => 'Mesa 12'])
            ->assertForbidden();

        $this->assertDatabaseMissing('tables', ['name' => 'Mesa 12']);
    }

    public function test_public_token_is_generated_automatically_and_is_not_the_id(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/tables", [
                'name' => 'Mesa 12',
                'public_token' => 'attacker-supplied-token',
            ])
            ->assertCreated();

        $token = $response->json('data.table.public_token');
        $id = $response->json('data.table.id');

        $this->assertNotSame('attacker-supplied-token', $token);
        $this->assertNotSame((string) $id, $token);
        $this->assertGreaterThanOrEqual(40, strlen($token));
    }

    public function test_public_token_is_unique_across_tables(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);

        $this->assertNotSame($tableA->public_token, $tableB->public_token);
    }

    public function test_restaurant_id_and_organization_id_from_payload_are_ignored(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $otherOrganization = Organization::factory()->create();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/tables", [
                'name' => 'Mesa 12',
                'restaurant_id' => 999999,
                'organization_id' => $otherOrganization->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.table.restaurant_id', $restaurant->id);
    }

    public function test_creating_a_table_under_a_restaurant_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/tables", ['name' => 'Mesa 12'])
            ->assertNotFound();

        $this->assertDatabaseMissing('tables', ['restaurant_id' => $restaurantB->id, 'name' => 'Mesa 12']);
    }
}

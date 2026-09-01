<?php

namespace Tests\Feature\Table;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TableShowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_view_a_table(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/tables/{$table->id}")
            ->assertOk()
            ->assertJsonPath('data.table.id', $table->id);
    }

    public function test_cashier_can_view_a_table(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');
        $table = $this->createTable($restaurant);

        $this->actingAs($cashier, 'web')
            ->getJson("/api/v1/tables/{$table->id}")
            ->assertOk();
    }

    public function test_kitchen_cannot_view_a_table(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/tables/{$table->id}")
            ->assertForbidden();
    }

    public function test_table_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/tables/{$tableB->id}")
            ->assertNotFound();
    }
}

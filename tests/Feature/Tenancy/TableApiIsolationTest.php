<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Tables\OpenTableAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Explicit cross-tenant security scenario for tables:
 *
 * Organization A / Restaurant A / Owner A / Table A
 * Organization B / Restaurant B / Owner B / Table B
 *
 * Owner A must never be able to read, list, update, open, or close
 * anything belonging to Organization B.
 */
class TableApiIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_a_cannot_view_table_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/tables/{$tableB->id}")
            ->assertNotFound();
    }

    public function test_owner_a_cannot_update_table_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/tables/{$tableB->id}", ['name' => 'Pwned'])
            ->assertNotFound();

        $this->assertDatabaseMissing('tables', ['name' => 'Pwned']);
    }

    public function test_owner_a_cannot_create_a_table_under_restaurant_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/tables", ['name' => 'Pwned'])
            ->assertNotFound();

        $this->assertDatabaseMissing('tables', ['name' => 'Pwned']);
    }

    public function test_owner_a_cannot_list_tables_of_restaurant_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $this->createTable($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/tables")
            ->assertNotFound();
    }

    public function test_owner_a_cannot_open_table_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/tables/{$tableB->id}/open", ['guest_count' => 4])
            ->assertNotFound();

        $this->assertDatabaseMissing('table_sessions', ['table_id' => $tableB->id]);
    }

    public function test_owner_a_cannot_close_table_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);
        $sessionB = app(OpenTableAction::class)->execute($tableB, $ownerB, 4);

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/tables/{$tableB->id}/close")
            ->assertNotFound();

        $this->assertSame('occupied', $sessionB->fresh()->status);
    }
}

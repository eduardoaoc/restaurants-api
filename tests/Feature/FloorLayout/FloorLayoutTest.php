<?php

namespace Tests\Feature\FloorLayout;

use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class FloorLayoutTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * Acts as one user, discarding any guard state left by a previous actor
     * in the same test (otherwise the first authenticated user sticks).
     */
    private function as(\App\Models\User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    /** @return array<string, mixed> */
    private function document(array $overrides = []): array
    {
        return array_replace_recursive([
            'version' => 1,
            'floor' => ['width' => 20, 'depth' => 16, 'gridStep' => 0.25],
            'tables' => [
                ['id' => (string) Str::uuid(), 'number' => 1, 'shape' => 'round', 'seats' => 2, 'position' => ['x' => -4.5, 'z' => 2.25], 'rotation' => 0],
                ['id' => (string) Str::uuid(), 'number' => 2, 'shape' => 'rectangular', 'seats' => 8, 'position' => ['x' => 3, 'z' => -1.5], 'rotation' => 90],
            ],
        ], $overrides);
    }

    private function url(Restaurant $restaurant): string
    {
        return "/api/v1/restaurants/{$restaurant->id}/floor-layout";
    }

    /** Replaces (not merges) the tables list of a document. */
    private function withTables(array $tables): array
    {
        $doc = $this->document();
        $doc['tables'] = $tables;

        return $doc;
    }

    private function table(array $overrides = []): array
    {
        return array_replace_recursive(
            ['id' => (string) Str::uuid(), 'number' => 1, 'shape' => 'square', 'seats' => 4, 'position' => ['x' => 0, 'z' => 0], 'rotation' => 0],
            $overrides
        );
    }

    public function test_get_returns_the_saved_layout(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $doc = $this->document();
        $this->as($owner)->putJson($this->url($restaurant), $doc)->assertOk();

        $this->as($owner)
            ->getJson($this->url($restaurant))
            ->assertOk()
            ->assertJsonPath('data.floor_layout.version', 1)
            ->assertJsonPath('data.floor_layout.floor.gridStep', 0.25)
            ->assertJsonPath('data.floor_layout.tables.0.id', $doc['tables'][0]['id'])
            ->assertJsonPath('data.floor_layout.tables.1.position.x', 3)
            ->assertJsonPath('data.floor_layout.tables.1.rotation', 90);
    }

    public function test_get_returns_null_when_no_layout_exists(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->as($owner)
            ->getJson($this->url($restaurant))
            ->assertOk()
            ->assertJsonPath('data.floor_layout', null);
    }

    public function test_put_persists_and_returns_the_canonical_document_and_audits(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $doc = $this->document();
        $doc['tables'][0]['unexpected'] = 'dropped';

        $this->as($owner)
            ->putJson($this->url($restaurant), $doc)
            ->assertOk()
            ->assertJsonPath('data.floor_layout.tables.0.number', 1)
            ->assertJsonMissingPath('data.floor_layout.tables.0.unexpected');

        $this->assertDatabaseHas('restaurant_floor_layouts', ['restaurant_id' => $restaurant->id, 'schema_version' => 1]);
        $this->assertDatabaseHas('audit_logs', ['restaurant_id' => $restaurant->id, 'event' => AuditLog::EVENT_FLOOR_LAYOUT_REPLACED]);
    }

    public function test_backend_table_mapping_and_entrance_round_trip(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = Table::factory()->create(['restaurant_id' => $restaurant->id]);
        $doc = $this->document();
        $doc['entrance'] = ['x' => 1.5, 'z' => -2.0];
        $doc['tables'][0]['backendTableId'] = $table->id;

        $this->as($owner)->putJson($this->url($restaurant), $doc)
            ->assertOk()
            ->assertJsonPath('data.floor_layout.entrance.x', 1.5)
            ->assertJsonPath('data.floor_layout.tables.0.backendTableId', $table->id);
    }

    public function test_backend_table_mapping_must_belong_to_restaurant_and_be_unique(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        [, , $otherRestaurant] = $this->createTenant();
        $foreignTable = Table::factory()->create(['restaurant_id' => $otherRestaurant->id]);
        $doc = $this->document();
        $doc['tables'][0]['backendTableId'] = $foreignTable->id;
        $this->as($owner)->putJson($this->url($restaurant), $doc)
            ->assertUnprocessable()->assertJsonValidationErrors(['tables.0.backendTableId']);

        $table = Table::factory()->create(['restaurant_id' => $restaurant->id]);
        $doc['tables'][0]['backendTableId'] = $table->id;
        $doc['tables'][1]['backendTableId'] = $table->id;
        $this->as($owner)->putJson($this->url($restaurant), $doc)
            ->assertUnprocessable()->assertJsonValidationErrors(['tables.1.backendTableId']);
    }

    public function test_missing_mapping_and_entrance_remain_backward_compatible(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->as($owner)->putJson($this->url($restaurant), $this->document())
            ->assertOk()
            ->assertJsonPath('data.floor_layout.entrance', null)
            ->assertJsonMissingPath('data.floor_layout.tables.0.backendTableId');
    }

    public function test_entrance_outside_floor_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $doc = $this->document(['entrance' => ['x' => 10.01, 'z' => 0]]);
        $this->as($owner)->putJson($this->url($restaurant), $doc)
            ->assertUnprocessable()->assertJsonValidationErrors(['entrance']);
    }

    public function test_put_replaces_the_existing_layout_entirely(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->as($owner)->putJson($this->url($restaurant), $this->document())->assertOk();

        $replacement = $this->withTables([$this->table(['number' => 7])]);
        $this->as($owner)->putJson($this->url($restaurant), $replacement)->assertOk();

        $this->assertDatabaseCount('restaurant_floor_layouts', 1);
        $this->as($owner)
            ->getJson($this->url($restaurant))
            ->assertJsonCount(1, 'data.floor_layout.tables')
            ->assertJsonPath('data.floor_layout.tables.0.number', 7);
    }

    public function test_an_empty_table_list_is_a_valid_layout(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->as($owner)->putJson($this->url($restaurant), $this->withTables([]))->assertOk();
        $this->as($owner)
            ->getJson($this->url($restaurant))
            ->assertJsonPath('data.floor_layout.tables', []);
    }

    public function test_manager_can_update_the_layout(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        $this->as($manager)->putJson($this->url($restaurant), $this->document())->assertOk();
    }

    public function test_waiter_can_read_but_not_update_the_layout(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->as($waiter)->getJson($this->url($restaurant))->assertOk();
        $this->as($waiter)->putJson($this->url($restaurant), $this->withTables([]))->assertForbidden();
        $this->assertDatabaseCount('restaurant_floor_layouts', 0);
    }

    public function test_kitchen_cannot_read_or_update(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->as($kitchen)->getJson($this->url($restaurant))->assertForbidden();
        $this->as($kitchen)->putJson($this->url($restaurant), $this->document())->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        [, , $restaurant] = $this->createTenant();

        $this->getJson($this->url($restaurant))->assertUnauthorized();
        $this->putJson($this->url($restaurant), $this->document())->assertUnauthorized();
    }

    public function test_another_organizations_layout_cannot_be_read(): void
    {
        [, $ownerA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $this->as($ownerB)->putJson($this->url($restaurantB), $this->document())->assertOk();

        $this->as($ownerA)->getJson($this->url($restaurantB))->assertNotFound();
    }

    public function test_another_organizations_layout_cannot_be_updated(): void
    {
        [, $ownerA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $original = $this->withTables([$this->table(['number' => 5])]);
        $this->as($ownerB)->putJson($this->url($restaurantB), $original)->assertOk();

        $this->as($ownerA)->putJson($this->url($restaurantB), $this->document())->assertNotFound();

        $this->as($ownerB)
            ->getJson($this->url($restaurantB))
            ->assertJsonPath('data.floor_layout.tables.0.number', 5);
    }

    public function test_staff_scoped_to_another_restaurant_of_the_same_organization_cannot_read_or_update(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $restaurantA, 'manager', 'M-1');

        $this->as($manager)->getJson($this->url($restaurantB))->assertNotFound();
        $this->as($manager)->putJson($this->url($restaurantB), $this->document())->assertNotFound();
        $this->assertDatabaseMissing('restaurant_floor_layouts', ['restaurant_id' => $restaurantB->id]);
    }

    public function test_unsupported_version_is_rejected_and_nothing_is_persisted(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->as($owner)
            ->putJson($this->url($restaurant), $this->document(['version' => 2]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['version']);
        $this->assertDatabaseCount('restaurant_floor_layouts', 0);
    }

    public function test_duplicate_table_numbers_and_ids_are_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $id = (string) Str::uuid();

        $this->as($owner)
            ->putJson($this->url($restaurant), $this->withTables([$this->table(['number' => 3]), $this->table(['number' => 3])]))
            ->assertStatus(422)->assertJsonValidationErrors(['tables.0.number', 'tables.1.number']);

        $this->as($owner)
            ->putJson($this->url($restaurant), $this->withTables([$this->table(['id' => $id, 'number' => 1]), $this->table(['id' => $id, 'number' => 2])]))
            ->assertStatus(422)->assertJsonValidationErrors(['tables.0.id']);
    }

    public function test_non_uuid_id_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->as($owner)
            ->putJson($this->url($restaurant), $this->withTables([$this->table(['id' => 'new-table-123'])]))
            ->assertStatus(422)->assertJsonValidationErrors(['tables.0.id']);
    }

    #[DataProvider('invalidTableFields')]
    public function test_invalid_table_fields_are_rejected(array $override, string $errorKey): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->as($owner)
            ->putJson($this->url($restaurant), $this->withTables([$this->table($override)]))
            ->assertStatus(422)->assertJsonValidationErrors([$errorKey]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidTableFields(): array
    {
        return [
            'unknown shape' => [['shape' => 'hexagon'], 'tables.0.shape'],
            'zero seats' => [['seats' => 0], 'tables.0.seats'],
            'too many seats' => [['seats' => 21], 'tables.0.seats'],
            'fractional seats' => [['seats' => 2.5], 'tables.0.seats'],
            'zero number' => [['number' => 0], 'tables.0.number'],
            'negative number' => [['number' => -3], 'tables.0.number'],
            'non-numeric x' => [['position' => ['x' => 'abc']], 'tables.0.position.x'],
            'absurd z' => [['position' => ['z' => 1e12]], 'tables.0.position.z'],
            'negative rotation' => [['rotation' => -15], 'tables.0.rotation'],
            'rotation 360' => [['rotation' => 360], 'tables.0.rotation'],
            'centre outside floor' => [['position' => ['x' => 11]], 'tables.0.position'],
        ];
    }

    public function test_invalid_floor_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->as($owner)
            ->putJson($this->url($restaurant), $this->document(['floor' => ['width' => 0, 'gridStep' => 5]]))
            ->assertStatus(422)->assertJsonValidationErrors(['floor.width', 'floor.gridStep']);
    }

    public function test_too_many_tables_are_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $tables = [];
        for ($i = 1; $i <= 201; $i++) {
            $tables[] = $this->table(['number' => $i]);
        }

        $this->as($owner)
            ->putJson($this->url($restaurant), $this->withTables($tables))
            ->assertStatus(422)->assertJsonValidationErrors(['tables']);
    }
}

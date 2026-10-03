<?php

namespace Tests\Feature\Table;

use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 8.2A — RestaurantSettings::waiter_table_management_enabled gates
 * table STRUCTURE changes for users holding manage_tables without
 * manage_floor_plan (waiter). Operational flows stay untouched.
 */
class TableStructureManagementTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * Sanctum's request guard caches the first authenticated user for the
     * whole test, and AuthenticateSession pins the session to the previous
     * user's password hash — forget both so each request really runs as
     * $user.
     */
    private function as(User $user): static
    {
        Auth::forgetGuards();
        $this->flushSession();

        return $this->actingAs($user, 'web');
    }

    private function setWaiterTableManagement(Restaurant $restaurant, bool $enabled): void
    {
        $restaurant->settings()->update(['waiter_table_management_enabled' => $enabled]);
    }

    /**
     * @return array{0: Restaurant, 1: User, 2: User}
     */
    private function restaurantWithWaiterAndManager(): array
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        return [$restaurant, $waiter, $manager];
    }

    // --- Create ---------------------------------------------------------

    public function test_waiter_can_create_a_table_when_enabled(): void
    {
        [$restaurant, $waiter] = $this->restaurantWithWaiterAndManager();

        $this->as($waiter)
            ->postJson("/api/v1/restaurants/{$restaurant->id}/tables", ['name' => 'Mesa W', 'number' => 7, 'capacity' => 4])
            ->assertCreated();

        $this->assertDatabaseHas('tables', ['restaurant_id' => $restaurant->id, 'name' => 'Mesa W']);
    }

    public function test_waiter_cannot_create_a_table_when_disabled(): void
    {
        [$restaurant, $waiter] = $this->restaurantWithWaiterAndManager();
        $this->setWaiterTableManagement($restaurant, false);

        $this->as($waiter)
            ->postJson("/api/v1/restaurants/{$restaurant->id}/tables", ['name' => 'Mesa W'])
            ->assertForbidden();

        $this->assertDatabaseMissing('tables', ['name' => 'Mesa W']);
    }

    public function test_manager_and_owner_can_create_a_table_when_disabled(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $this->setWaiterTableManagement($restaurant, false);

        $this->as($manager)
            ->postJson("/api/v1/restaurants/{$restaurant->id}/tables", ['name' => 'Mesa M'])
            ->assertCreated();
        $this->as($owner)
            ->postJson("/api/v1/restaurants/{$restaurant->id}/tables", ['name' => 'Mesa O'])
            ->assertCreated();
    }

    // --- Update ---------------------------------------------------------

    public function test_waiter_can_update_name_number_and_capacity_when_enabled(): void
    {
        [$restaurant, $waiter] = $this->restaurantWithWaiterAndManager();
        $table = $this->createTable($restaurant, 'Old', 1);

        $this->as($waiter)
            ->patchJson("/api/v1/tables/{$table->id}", ['name' => 'New', 'number' => 2, 'capacity' => 6])
            ->assertOk()
            ->assertJsonPath('data.table.name', 'New')
            ->assertJsonPath('data.table.capacity', 6);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function structuralPayloads(): array
    {
        return [
            'name' => [['name' => 'Renamed']],
            'number' => [['number' => 99]],
            'capacity' => [['capacity' => 8]],
            'layout' => [['layout_x' => 0.5, 'layout_y' => 0.5]],
            'name + status' => [['name' => 'Renamed', 'status' => 'blocked']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('structuralPayloads')]
    public function test_waiter_cannot_update_structural_fields_when_disabled(array $payload): void
    {
        [$restaurant, $waiter] = $this->restaurantWithWaiterAndManager();
        $table = $this->createTable($restaurant, 'Original', 1);
        $this->setWaiterTableManagement($restaurant, false);

        $this->as($waiter)
            ->patchJson("/api/v1/tables/{$table->id}", $payload)
            ->assertForbidden();

        $table->refresh();
        $this->assertSame('Original', $table->name);
        $this->assertSame(1, $table->number);
        $this->assertSame('active', $table->status);
    }

    public function test_manager_can_update_structural_fields_when_disabled(): void
    {
        [$restaurant, , $manager] = $this->restaurantWithWaiterAndManager();
        $zone = $this->createZone($this->createFloor($restaurant));
        $table = $this->createTable($restaurant, 'Old', 1);
        $this->setWaiterTableManagement($restaurant, false);

        $this->as($manager)
            ->patchJson("/api/v1/tables/{$table->id}", [
                'name' => 'New', 'number' => 3, 'capacity' => 2,
                'zone_id' => $zone->id, 'layout_x' => 0.3, 'layout_width' => 120,
            ])
            ->assertOk()
            ->assertJsonPath('data.table.name', 'New')
            ->assertJsonPath('data.table.zone_id', $zone->id);
    }

    public function test_waiter_can_still_change_operational_status_when_disabled(): void
    {
        [$restaurant, $waiter] = $this->restaurantWithWaiterAndManager();
        $table = $this->createTable($restaurant);
        $this->setWaiterTableManagement($restaurant, false);

        $this->as($waiter)
            ->patchJson("/api/v1/tables/{$table->id}", ['status' => 'blocked'])
            ->assertOk()
            ->assertJsonPath('data.table.status', 'blocked');
    }

    public function test_toggle_is_read_at_request_time_toctou(): void
    {
        [$restaurant, $waiter, $manager] = $this->restaurantWithWaiterAndManager();
        $table = $this->createTable($restaurant, 'Original', 1);

        // The waiter "opened the form" while enabled...
        $this->as($waiter)->getJson("/api/v1/tables/{$table->id}")->assertOk();

        // ...the manager disables it through the real settings endpoint...
        $this->as($manager)
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['waiter_table_management_enabled' => false])
            ->assertOk();

        // ...and the waiter's later submit is refused.
        $this->as($waiter)
            ->patchJson("/api/v1/tables/{$table->id}", ['name' => 'Stale form'])
            ->assertForbidden();

        $this->as($manager)
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['waiter_table_management_enabled' => true])
            ->assertOk();

        $this->as($waiter)
            ->patchJson("/api/v1/tables/{$table->id}", ['name' => 'Fresh form'])
            ->assertOk();
    }

    // --- Layout / floor plan (already manage_floor_plan-only) ------------

    public function test_waiter_cannot_move_resize_or_rezone_via_floor_plan_layout_when_disabled(): void
    {
        [$restaurant, $waiter, $manager] = $this->restaurantWithWaiterAndManager();
        $floor = $this->createFloor($restaurant);
        $zone = $this->createZone($floor);
        $table = $this->createTable($restaurant);
        $this->setWaiterTableManagement($restaurant, false);

        $payload = ['tables' => [[
            'id' => $table->id, 'zone_id' => $zone->id,
            'layout_x' => 0.9, 'layout_y' => 0.1, 'layout_width' => 200, 'layout_height' => 100,
        ]]];

        $this->as($waiter)
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/floor-plan/layout", $payload)
            ->assertForbidden();
        $this->as($waiter)
            ->patchJson("/api/v1/tables/{$table->id}", ['zone_id' => $zone->id])
            ->assertForbidden();
        $this->as($waiter)
            ->postJson("/api/v1/restaurants/{$restaurant->id}/zones", ['name' => 'Terraza', 'floor_id' => $floor->id])
            ->assertForbidden();
        $this->as($waiter)
            ->postJson("/api/v1/restaurants/{$restaurant->id}/floors", ['name' => 'Planta 2'])
            ->assertForbidden();

        $this->assertNull($table->refresh()->zone_id);

        $this->as($manager)
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/floor-plan/layout", $payload)
            ->assertOk();
        $this->assertSame($zone->id, $table->refresh()->zone_id);
    }

    public function test_enabling_the_toggle_never_grants_a_waiter_layout_access(): void
    {
        [$restaurant, $waiter] = $this->restaurantWithWaiterAndManager();
        $table = $this->createTable($restaurant);

        $this->as($waiter)
            ->patchJson("/api/v1/tables/{$table->id}", ['layout_x' => 0.5])
            ->assertForbidden();
        $this->as($waiter)
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/floor-plan/layout", ['tables' => [['id' => $table->id, 'layout_x' => 0.5]]])
            ->assertForbidden();
    }

    // --- Operational flows are never gated -------------------------------

    public function test_waiter_operational_flows_still_work_when_disabled(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->setWaiterTableManagement($restaurant, false);

        $this->as($waiter)->getJson("/api/v1/restaurants/{$restaurant->id}/tables")->assertOk();
        $this->as($waiter)->getJson("/api/v1/tables/{$table->id}")->assertOk()
            ->assertJsonPath('data.table.id', $table->id);
        $this->as($waiter)->getJson("/api/v1/tables/resolve/{$table->public_token}")->assertOk()
            ->assertJsonPath('data.table.id', $table->id);
        $this->as($waiter)->getJson("/api/v1/restaurants/{$restaurant->id}/floor-plan")->assertOk();

        $this->as($waiter)
            ->postJson("/api/v1/tables/{$table->id}/open", ['guest_count' => 2])
            ->assertSuccessful();

        $this->as($waiter)
            ->postJson("/api/v1/tables/{$table->id}/orders", ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]])
            ->assertCreated();

        $this->as($waiter)->getJson('/api/v1/table-requests?table_id='.$table->id)->assertOk();
        $this->assertSame($table->id, $table->activeSession()->firstOrFail()->table_id);
    }

    public function test_waiter_open_and_close_are_not_gated_when_disabled(): void
    {
        [$restaurant, $waiter] = $this->restaurantWithWaiterAndManager();
        $table = $this->createTable($restaurant);
        $this->setWaiterTableManagement($restaurant, false);

        $this->as($waiter)->postJson("/api/v1/tables/{$table->id}/open", ['guest_count' => 2])->assertSuccessful();

        // Authorization passes; the existing billing precondition (no
        // billable orders yet) is what answers — never a 403.
        $this->as($waiter)->postJson("/api/v1/tables/{$table->id}/close")->assertStatus(409);
    }

    // --- Multi-restaurant ------------------------------------------------

    public function test_setting_is_per_restaurant_for_the_same_waiter(): void
    {
        [$organization, $owner, $malvarrosa] = $this->createTenant();
        $ruzafa = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiter = $this->createStaffAcrossRestaurants($organization, [$malvarrosa, $ruzafa], 'waiter', $owner);
        $this->setWaiterTableManagement($malvarrosa, false);

        $tableMalvarrosa = $this->createTable($malvarrosa, 'M1', 1);
        $tableRuzafa = $this->createTable($ruzafa, 'R1', 1);

        $this->as($waiter)
            ->postJson("/api/v1/restaurants/{$malvarrosa->id}/tables", ['name' => 'Nope'])
            ->assertForbidden();
        $this->as($waiter)
            ->patchJson("/api/v1/tables/{$tableMalvarrosa->id}", ['name' => 'Nope'])
            ->assertForbidden();

        $this->as($waiter)
            ->postJson("/api/v1/restaurants/{$ruzafa->id}/tables", ['name' => 'Yes'])
            ->assertCreated();
        $this->as($waiter)
            ->patchJson("/api/v1/tables/{$tableRuzafa->id}", ['name' => 'Yes too'])
            ->assertOk();

        $this->assertSame(1, Table::query()->where('restaurant_id', $ruzafa->id)->where('name', 'Yes')->count());
        $this->assertSame(0, Table::query()->where('name', 'Nope')->count());
    }
}

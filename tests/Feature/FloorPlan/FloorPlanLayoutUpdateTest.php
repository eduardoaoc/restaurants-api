<?php

namespace Tests\Feature\FloorPlan;

use App\Actions\Tables\OpenTableAction;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class FloorPlanLayoutUpdateTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_bulk_update_layout_for_multiple_tables(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant);
        $zone = $this->createZone($floor);
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/floor-plan/layout", [
                'tables' => [
                    ['id' => $tableA->id, 'zone_id' => $zone->id, 'layout_x' => 0.25, 'layout_y' => 0.4, 'layout_rotation' => 90, 'layout_shape' => 'round', 'layout_width' => 100, 'layout_height' => 100],
                    ['id' => $tableB->id, 'zone_id' => $zone->id, 'layout_x' => 0.5, 'layout_y' => 0.5],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.tables_updated_count', 2);

        $this->assertDatabaseHas('tables', [
            'id' => $tableA->id, 'zone_id' => $zone->id, 'layout_rotation' => 90, 'layout_shape' => 'round',
        ]);
        $this->assertDatabaseHas('tables', ['id' => $tableB->id, 'zone_id' => $zone->id]);
    }

    public function test_manager_with_manage_floor_plan_can_bulk_update_layout(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $table = $this->createTable($restaurant);

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/floor-plan/layout", [
                'tables' => [['id' => $table->id, 'layout_x' => 0.1, 'layout_y' => 0.1]],
            ])
            ->assertOk();
    }

    public function test_waiter_cannot_bulk_update_layout(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/floor-plan/layout", [
                'tables' => [['id' => $table->id, 'layout_x' => 0.1, 'layout_y' => 0.1]],
            ])
            ->assertForbidden();
    }

    public function test_a_table_from_another_restaurant_is_rejected_and_nothing_is_persisted(): void
    {
        [, $owner, $restaurantA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $tableA = $this->createTable($restaurantA);
        $tableB = $this->createTable($restaurantB);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurantA->id}/floor-plan/layout", [
                'tables' => [
                    ['id' => $tableA->id, 'layout_x' => 0.9, 'layout_y' => 0.9],
                    ['id' => $tableB->id, 'layout_x' => 0.1, 'layout_y' => 0.1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tables']);

        // Atomic: even tableA (which was otherwise valid) must be untouched.
        $this->assertDatabaseMissing('tables', ['id' => $tableA->id, 'layout_x' => 0.9]);
    }

    public function test_a_zone_from_another_restaurant_is_rejected(): void
    {
        [, $owner, $restaurantA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $table = $this->createTable($restaurantA);
        $floorB = $this->createFloor($restaurantB);
        $zoneB = $this->createZone($floorB);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurantA->id}/floor-plan/layout", [
                'tables' => [['id' => $table->id, 'zone_id' => $zoneB->id]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tables']);

        $this->assertDatabaseHas('tables', ['id' => $table->id, 'zone_id' => null]);
    }

    public function test_an_out_of_range_coordinate_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/floor-plan/layout", [
                'tables' => [['id' => $table->id, 'layout_x' => 1.5, 'layout_y' => 0.1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tables.0.layout_x']);
    }

    public function test_an_invalid_shape_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/floor-plan/layout", [
                'tables' => [['id' => $table->id, 'layout_shape' => 'triangle']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tables.0.layout_shape']);
    }

    public function test_bulk_layout_update_records_a_single_aggregated_audit_event(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/floor-plan/layout", [
                'tables' => [
                    ['id' => $tableA->id, 'layout_x' => 0.1, 'layout_y' => 0.1],
                    ['id' => $tableB->id, 'layout_x' => 0.2, 'layout_y' => 0.2],
                ],
            ])
            ->assertOk();

        $this->assertSame(1, AuditLog::query()
            ->where('event', AuditLog::EVENT_FLOOR_PLAN_LAYOUT_UPDATED)
            ->where('restaurant_id', $restaurant->id)
            ->count());

        $log = AuditLog::query()->where('event', AuditLog::EVENT_FLOOR_PLAN_LAYOUT_UPDATED)->firstOrFail();
        $this->assertSame(2, $log->metadata['tables_updated_count']);
    }

    public function test_layout_update_does_not_affect_active_table_session(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = app(OpenTableAction::class)->execute($table, $owner, 4);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/floor-plan/layout", [
                'tables' => [['id' => $table->id, 'layout_x' => 0.6, 'layout_y' => 0.6]],
            ])
            ->assertOk();

        $session->refresh();
        $this->assertSame('occupied', $session->status);
        $this->assertSame(4, $session->guest_count);
    }
}

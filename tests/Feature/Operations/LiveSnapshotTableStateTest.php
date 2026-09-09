<?php

namespace Tests\Feature\Operations;

use App\Models\Order;
use App\Models\TableRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 5 — Operations Live: per-table primary_status derivation and
 * precedence, driven end-to-end through real domain records (not the
 * resolver unit directly — see TableOperationalStateResolverTest for
 * that).
 */
class LiveSnapshotTableStateTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function fetchTableView($owner, $restaurant, int $tableId): array
    {
        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $all = collect($response->json('data.unassigned_tables'))
            ->merge(collect($response->json('data.floors'))->flatMap(fn ($floor) => collect($floor['zones'])->flatMap(fn ($zone) => $zone['tables'])));

        return $all->firstWhere('id', $tableId);
    }

    public function test_table_without_session_is_free(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $view = $this->fetchTableView($owner, $restaurant, $table->id);

        $this->assertSame('free', $view['primary_status']);
        $this->assertSame([], $view['flags']);
        $this->assertNull($view['session']);
    }

    public function test_table_with_session_and_no_events_is_occupied(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner, 2);

        $view = $this->fetchTableView($owner, $restaurant, $table->id);

        $this->assertSame('occupied', $view['primary_status']);
        $this->assertNotNull($view['session']);
        $this->assertSame(2, $view['session']['guest_count']);
    }

    public function test_table_with_preparing_order(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        // Staff order starts confirmed — falls in the "preparing" umbrella.

        $view = $this->fetchTableView($owner, $restaurant, $table->id);

        $this->assertSame('preparing', $view['primary_status']);
        $this->assertContains('preparing_order', $view['flags']);
        $this->assertSame(1, $view['orders']['preparing']);
    }

    public function test_table_with_ready_order(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->advanceOrderTo($order, Order::STATUS_READY, $owner);

        $view = $this->fetchTableView($owner, $restaurant, $table->id);

        $this->assertSame('ready', $view['primary_status']);
        $this->assertContains('ready_order', $view['flags']);
    }

    public function test_table_with_pending_waiter_request(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $view = $this->fetchTableView($owner, $restaurant, $table->id);

        $this->assertSame('waiter_requested', $view['primary_status']);
        $this->assertContains('waiter_requested', $view['flags']);
    }

    public function test_table_with_pending_bill_request_outranks_everything(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->advanceOrderTo($order, Order::STATUS_READY, $owner);
        $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);

        $view = $this->fetchTableView($owner, $restaurant, $table->id);

        $this->assertSame('bill_requested', $view['primary_status']);
        $this->assertContains('bill_requested', $view['flags']);
        $this->assertContains('waiter_requested', $view['flags']);
        $this->assertContains('ready_order', $view['flags']);
    }

    public function test_table_without_zone_appears_in_unassigned_tables(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $ids = collect($response->json('data.unassigned_tables'))->pluck('id')->all();
        $this->assertContains($table->id, $ids);
        $this->assertSame([], $response->json('data.floors'));
    }

    public function test_table_with_zone_appears_nested_under_its_floor_and_zone(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $floor = $this->createFloor($restaurant, 'Ground Floor');
        $zone = $this->createZone($floor, 'Terrace');
        $table = $this->createTable($restaurant);
        $table->update(['zone_id' => $zone->id]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $floors = $response->json('data.floors');
        $this->assertCount(1, $floors);
        $this->assertSame($floor->id, $floors[0]['id']);
        $this->assertCount(1, $floors[0]['zones']);
        $this->assertSame($zone->id, $floors[0]['zones'][0]['id']);
        $this->assertSame([$table->id], collect($floors[0]['zones'][0]['tables'])->pluck('id')->all());

        $unassignedIds = collect($response->json('data.unassigned_tables'))->pluck('id')->all();
        $this->assertNotContains($table->id, $unassignedIds);
    }
}

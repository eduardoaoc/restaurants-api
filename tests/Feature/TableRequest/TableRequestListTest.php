<?php

namespace Tests\Feature\TableRequest;

use App\Models\Restaurant;
use App\Models\TableRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TableRequestListTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_content_type_is_json(): void
    {
        [, $owner] = $this->createTenant();
        $this->actingAs($owner, 'web')->getJson('/api/v1/table-requests')->assertHeader('Content-Type', 'application/json');
    }

    // --- Filters ------------------------------------------------------

    public function test_filter_by_status(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $pending = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $acknowledged = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $this->advanceTableRequestTo($acknowledged, TableRequest::STATUS_ACKNOWLEDGED, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/table-requests?status=pending')
            ->assertOk();

        $this->assertSame([$pending->id], collect($response->json('data.table_requests'))->pluck('id')->all());
    }

    public function test_filter_by_type(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $callWaiter = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/table-requests?type=call_waiter')
            ->assertOk();

        $this->assertSame([$callWaiter->id], collect($response->json('data.table_requests'))->pluck('id')->all());
    }

    public function test_filter_by_restaurant_id(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $tableA = $this->createTable($restaurantA);
        $this->openSession($tableA, $owner);
        $requestA = $this->createTableRequest($tableA, TableRequest::TYPE_CALL_WAITER);

        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-requests?restaurant_id={$restaurantA->id}")
            ->assertOk();

        $this->assertSame([$requestA->id], collect($response->json('data.table_requests'))->pluck('id')->all());
    }

    public function test_invalid_status_filter_returns_422(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/table-requests?status=not-a-real-status')
            ->assertStatus(422);
    }

    public function test_invalid_type_filter_returns_422(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/table-requests?type=not-a-real-type')
            ->assertStatus(422);
    }

    // --- Ordering -----------------------------------------------------

    public function test_requests_are_returned_oldest_first(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $first = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $first->forceFill(['created_at' => now()->subMinutes(10)])->save();
        $this->advanceTableRequestTo($first, TableRequest::STATUS_CANCELLED, $owner);

        $second = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $second->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/table-requests')->assertOk();

        $this->assertSame(
            [$first->id, $second->id],
            collect($response->json('data.table_requests'))->pluck('id')->all()
        );
    }

    // --- Resource contract ------------------------------------------

    public function test_table_request_resource_contract(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant, 'Mesa 12', 12);
        $this->openSession($table, $owner);
        $tableRequest = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER, 'Ayuda por favor');
        $this->advanceTableRequestTo($tableRequest, TableRequest::STATUS_ACKNOWLEDGED, $waiter);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-requests/{$tableRequest->id}")
            ->assertOk();

        $json = $response->json('data.table_request');

        $this->assertEqualsCanonicalizing([
            'id', 'type', 'status', 'restaurant', 'table', 'note',
            'created_at', 'acknowledged_at', 'acknowledged_by',
            'completed_at', 'completed_by', 'cancelled_at', 'cancelled_by',
        ], array_keys($json));

        $this->assertSame('Ayuda por favor', $json['note']);
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($json['restaurant']));
        $this->assertEqualsCanonicalizing(['id', 'name', 'number'], array_keys($json['table']));
        $this->assertSame(['id' => $waiter->id, 'name' => $waiter->name], $json['acknowledged_by']);
        $this->assertNull($json['completed_by']);
        $this->assertNull($json['cancelled_by']);

        $jsonEncoded = json_encode($json);
        $this->assertStringNotContainsString($waiter->email, $jsonEncoded);
        $this->assertStringNotContainsString('table_session_id', $jsonEncoded);
    }

    public function test_pending_request_has_null_actor_fields(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $tableRequest = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-requests/{$tableRequest->id}")
            ->assertOk();

        $json = $response->json('data.table_request');
        $this->assertNull($json['acknowledged_at']);
        $this->assertNull($json['acknowledged_by']);
        $this->assertNull($json['completed_at']);
        $this->assertNull($json['completed_by']);
        $this->assertNull($json['cancelled_at']);
        $this->assertNull($json['cancelled_by']);
    }
}

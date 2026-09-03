<?php

namespace Tests\Feature\Dashboard;

use App\Models\TableRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * tables.sessions_opened/sessions_closed respect the period (opened_at/
 * closed_at); tables.current_active is a snapshot of right now and does
 * NOT respect the period. requests.call_waiter/request_bill are counted
 * by created_at; requests.completed requires status=completed AND
 * completed_at in the period. See RestaurantDashboardService.
 */
class RestaurantDashboardTablesAndRequestsTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_sessions_opened_and_closed_respect_the_period(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $tableA = $this->createTable($restaurant, 'A');
        $sessionA = $this->openSession($tableA, $owner);
        $this->closeSessionWithFullPayment($sessionA, $owner);

        $tableB = $this->createTable($restaurant, 'B');
        $this->openSession($tableB, $owner);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.dashboard.tables.sessions_opened', 2)
            ->assertJsonPath('data.dashboard.tables.sessions_closed', 1);
    }

    public function test_current_active_is_a_snapshot_independent_of_the_period(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $tableA = $this->createTable($restaurant, 'A');
        $this->openSession($tableA, $owner);
        $tableB = $this->createTable($restaurant, 'B');
        $this->openSession($tableB, $owner);

        // A period entirely before the sessions were opened would show zero
        // sessions_opened, but current_active must still reflect "now".
        $this->travelTo(CarbonImmutable::create(2026, 6, 1));

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard?from=2026-01-01&to=2026-01-31")
            ->assertOk()
            ->assertJsonPath('data.dashboard.tables.sessions_opened', 0)
            ->assertJsonPath('data.dashboard.tables.current_active', 2);
    }

    public function test_requests_created_and_completed_counts(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $tableA = $this->createTable($restaurant, 'A');
        $this->openSession($tableA, $owner);
        $requestA = $this->createTableRequest($tableA, TableRequest::TYPE_CALL_WAITER);

        $tableB = $this->createTable($restaurant, 'B');
        $this->openSession($tableB, $owner);
        $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $tableC = $this->createTable($restaurant, 'C');
        $this->openSession($tableC, $owner);
        $this->createTableRequest($tableC, TableRequest::TYPE_REQUEST_BILL);

        // Only requestA is driven to completion.
        $this->advanceTableRequestTo($requestA, TableRequest::STATUS_COMPLETED, $owner);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.dashboard.requests.call_waiter', 2)
            ->assertJsonPath('data.dashboard.requests.request_bill', 1)
            ->assertJsonPath('data.dashboard.requests.completed', 1);
    }

    public function test_acknowledged_but_not_completed_request_does_not_count_as_completed(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $request = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $this->advanceTableRequestTo($request, TableRequest::STATUS_ACKNOWLEDGED, $owner);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.dashboard.requests.call_waiter', 1)
            ->assertJsonPath('data.dashboard.requests.completed', 0);
    }
}

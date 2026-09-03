<?php

namespace Tests\Feature\Staff;

use App\Actions\Orders\ApproveOrderAction;
use App\Actions\Staff\CreateStaffReviewAction;
use App\Models\Order;
use App\Models\TableRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Exercises each of the six objective metrics' exact counting semantics
 * and timestamp mapping, plus the rating average's precision — the core
 * guarantees of StaffPerformanceService that the higher-level scope tests
 * (StaffPerformanceMeTest/ShowTest) don't drill into.
 */
class StaffPerformanceMetricsTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_tables_served_counts_distinct_table_sessions_not_orders(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $waiter);
        $rp = $this->createRestaurantProduct($restaurant, $this->createProduct($organization));

        $order1 = $this->createWaiterOrder($table, $waiter, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $order2 = $this->createWaiterOrder($table, $waiter, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order1, Order::STATUS_SERVED, $waiter);
        $this->advanceOrderTo($order2, Order::STATUS_SERVED, $waiter);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.performance.metrics.tables_served', 1)
            ->assertJsonPath('data.performance.metrics.orders_served', 2);
    }

    public function test_orders_created_counts_by_created_by_and_created_at(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $waiter);
        $rp = $this->createRestaurantProduct($restaurant, $this->createProduct($organization));

        $this->createWaiterOrder($table, $waiter, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->createWaiterOrder($table, $waiter, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.performance.metrics.orders_created', 2);
    }

    public function test_customer_orders_approved_counts_by_approved_by_and_approved_at(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->requireOrderApproval($restaurant);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $waiter);
        $rp = $this->createRestaurantProduct($restaurant, $this->createProduct($organization));

        $customerOrder = $this->createCustomerOrder($table, [
            ['restaurant_product_id' => $rp->id, 'quantity' => 1],
        ]);
        $this->assertSame(Order::STATUS_WAITING_APPROVAL, $customerOrder->status);

        app(ApproveOrderAction::class)->execute($customerOrder, $waiter);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.performance.metrics.customer_orders_approved', 1)
            // The approval doesn't make the waiter the order's creator.
            ->assertJsonPath('data.performance.metrics.orders_created', 0);
    }

    public function test_table_requests_handled_requires_completion_not_just_acknowledgement(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $waiter);

        $requestAcknowledgedOnly = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $this->advanceTableRequestTo($requestAcknowledgedOnly, TableRequest::STATUS_ACKNOWLEDGED, $waiter);

        $requestCompleted = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $this->advanceTableRequestTo($requestCompleted, TableRequest::STATUS_COMPLETED, $waiter);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.performance.metrics.table_requests_handled', 1);
    }

    public function test_sessions_closed_counts_by_closed_by_and_closed_at(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $waiter);

        $this->closeSessionWithFullPayment($session, $waiter);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.performance.metrics.sessions_closed', 1);
    }

    public function test_metrics_use_the_correct_timestamp_per_metric_for_period_filtering(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $waiter);
        $rp = $this->createRestaurantProduct($restaurant, $this->createProduct($organization));

        $this->travelTo(CarbonImmutable::create(2026, 1, 15));
        $order = $this->createWaiterOrder($table, $waiter, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->travelTo(CarbonImmutable::create(2026, 2, 15));
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $waiter);

        // January window: created_at falls inside, served_at does not —
        // orders_created counts it, orders_served does not.
        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/performance?from=2026-01-01&to=2026-01-31")
            ->assertOk()
            ->assertJsonPath('data.performance.metrics.orders_created', 1)
            ->assertJsonPath('data.performance.metrics.orders_served', 0);

        // February window: the reverse.
        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/performance?from=2026-02-01&to=2026-02-28")
            ->assertOk()
            ->assertJsonPath('data.performance.metrics.orders_created', 0)
            ->assertJsonPath('data.performance.metrics.orders_served', 1);
    }

    public function test_rating_average_is_rounded_to_two_decimal_places(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        app(CreateStaffReviewAction::class)->execute($organization, $restaurant, $waiter, $owner, 5, null);
        app(CreateStaffReviewAction::class)->execute($organization, $restaurant, $waiter, $owner, 4, null);
        app(CreateStaffReviewAction::class)->execute($organization, $restaurant, $waiter, $owner, 5, null);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.performance.rating.average', '4.67')
            ->assertJsonPath('data.performance.rating.review_count', 3);
    }
}

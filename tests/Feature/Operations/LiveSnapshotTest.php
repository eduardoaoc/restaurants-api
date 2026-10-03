<?php

namespace Tests\Feature\Operations;

use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 5 — Operations Live: empty restaurant, summary correctness,
 * billing per table, and read-only safety.
 */
class LiveSnapshotTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_empty_restaurant_returns_a_valid_empty_snapshot(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $response->assertJsonPath('data.restaurant.id', $restaurant->id)
            ->assertJsonPath('data.summary.tables.total', 0)
            ->assertJsonPath('data.summary.tables.free', 0)
            ->assertJsonPath('data.summary.tables.occupied', 0)
            ->assertJsonPath('data.summary.tables.occupancy_rate', 0)
            ->assertJsonPath('data.summary.active_sessions', 0)
            ->assertJsonPath('data.summary.active_guests', 0)
            ->assertJsonPath('data.summary.staff.active', 0)
            ->assertJsonPath('data.summary.requests.pending', 0)
            ->assertJsonPath('data.summary.sales.received_today', '0.00')
            ->assertJsonPath('data.operation.health_score', 100)
            ->assertJsonPath('data.operation.health_level', 'healthy')
            ->assertJsonPath('data.operation.bottleneck', null)
            ->assertJsonPath('data.floors', [])
            ->assertJsonPath('data.unassigned_tables', [])
            ->assertJsonPath('data.staff', [])
            ->assertJsonPath('data.alerts', []);

        $this->assertNotNull($response->json('data.generated_at'));
    }

    public function test_restaurant_name_timezone_and_currency_are_returned(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'Europe/Madrid', 'currency' => 'EUR']);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk()
            ->assertJsonPath('data.restaurant.name', $restaurant->name)
            ->assertJsonPath('data.restaurant.timezone', 'Europe/Madrid')
            ->assertJsonPath('data.restaurant.currency', 'EUR');
    }

    /**
     * Regression guard: Carbon 3's diffInSeconds() defaults to a SIGNED
     * float ($later->diffInSeconds($earlier) is NEGATIVE, unlike Carbon
     * 2's absolute-by-default behavior) — see ElapsedTime. A session
     * opened several minutes ago must show a positive elapsed_seconds,
     * not a negative or near-zero one.
     */
    public function test_elapsed_seconds_is_positive_for_a_session_opened_in_the_past(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        $this->openSession($table, $owner);

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:04:00', 'UTC'));
        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $tableView = collect($response->json('data.unassigned_tables'))->firstWhere('id', $table->id);
        $this->assertSame(240, $tableView['session']['elapsed_seconds']);

        Carbon::setTestNow();
    }

    public function test_summary_counts_active_sessions_guests_and_free_tables(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $this->createTable($restaurant); // stays free

        $this->openSession($tableA, $owner, 3);
        $this->openSession($tableB, $owner, 5);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk()
            ->assertJsonPath('data.summary.tables.total', 3)
            ->assertJsonPath('data.summary.tables.occupied', 2)
            ->assertJsonPath('data.summary.tables.free', 1)
            ->assertJsonPath('data.summary.tables.occupancy_rate', round(2 / 3, 4))
            ->assertJsonPath('data.summary.active_sessions', 2)
            ->assertJsonPath('data.summary.active_guests', 8);
    }

    public function test_billing_summary_reflects_partial_payment_without_duplicating_calculation(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 84.50);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, '40.00');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $tableView = collect($response->json('data.unassigned_tables'))->firstWhere('id', $table->id);

        $this->assertSame('84.50', $tableView['billing']['total']);
        $this->assertSame('40.00', $tableView['billing']['paid']);
        $this->assertSame('44.50', $tableView['billing']['outstanding']);
        $this->assertSame('partial', $tableView['billing']['status']);
    }

    public function test_table_with_no_orders_has_null_billing(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $tableView = collect($response->json('data.unassigned_tables'))->firstWhere('id', $table->id);
        $this->assertNull($tableView['billing']);
    }

    public function test_reading_the_snapshot_produces_no_writes(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $sessionUpdatedAt = $session->updated_at;
        $tableUpdatedAt = $table->updated_at;
        // openSession() above legitimately wrote its own table_session.opened
        // entry — this test asserts the GET adds no MORE audit rows, not
        // that the table is empty.
        $auditLogCountBeforeGet = AuditLog::query()->count();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $this->assertSame($sessionUpdatedAt->toIso8601String(), $session->fresh()->updated_at->toIso8601String());
        $this->assertSame($tableUpdatedAt->toIso8601String(), $table->fresh()->updated_at->toIso8601String());
        $this->assertDatabaseCount('audit_logs', $auditLogCountBeforeGet);
    }
}

<?php

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — average_ticket = revenue / distinct sessions with payments,
 * exactly the semantics already established for /dashboard (item 69).
 */
class AnalyticsAverageTicketTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_average_ticket_divides_revenue_by_distinct_paying_sessions(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product, 100.0);

        // Session A: two payments (30 + 20) — one session, not two.
        $tableA = $this->createTable($restaurant);
        $sessionA = $this->openSession($tableA, $owner);
        $this->createWaiterOrder($tableA, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->recordPayment($sessionA, $owner, '30.00');
        $this->recordPayment($sessionA, $owner, '20.00');

        // Session B: one payment (50).
        $tableB = $this->createTable($restaurant);
        $sessionB = $this->openSession($tableB, $owner);
        $this->createWaiterOrder($tableB, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->recordPayment($sessionB, $owner, '50.00');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $this->assertSame('100.00', $response->json('data.summary.revenue'));
        $this->assertSame(2, $response->json('data.summary.sessions_with_payments'));
        $this->assertSame('50.00', $response->json('data.summary.average_ticket'));
    }

    public function test_average_ticket_is_zero_when_there_are_no_payments(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics")
            ->assertOk()
            ->assertJsonPath('data.summary.average_ticket', '0.00');
    }
}

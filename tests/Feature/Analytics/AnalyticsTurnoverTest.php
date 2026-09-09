<?php

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — table_turnover = closed_sessions / number_of_tables in the
 * period. Open sessions never count; zero tables never divides by zero.
 */
class AnalyticsTurnoverTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_only_closed_sessions_count_toward_turnover(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);

        $sessionA = $this->openSession($tableA, $owner);
        $this->closeSessionWithFullPayment($sessionA, $owner);

        // Still open — must not count.
        $this->openSession($tableB, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $this->assertSame(1, $response->json('data.table_turnover.closed_sessions'));
        $this->assertSame(0.5, $response->json('data.table_turnover.turnover_per_table'));
    }

    public function test_multiple_closed_sessions_on_the_same_table_all_count(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $session1 = $this->openSession($table, $owner);
        $this->closeSessionWithFullPayment($session1, $owner);

        $session2 = $this->openSession($table, $owner);
        $this->closeSessionWithFullPayment($session2, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $this->assertSame(2, $response->json('data.table_turnover.closed_sessions'));
        $this->assertSame(2, $response->json('data.table_turnover.turnover_per_table'));
    }

    public function test_zero_tables_returns_zero_turnover(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics")
            ->assertOk()
            ->assertJsonPath('data.table_turnover.closed_sessions', 0)
            ->assertJsonPath('data.table_turnover.turnover_per_table', 0);
    }
}

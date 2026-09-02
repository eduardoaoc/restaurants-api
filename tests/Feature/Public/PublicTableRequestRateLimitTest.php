<?php

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicTableRequestRateLimitTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_exceeding_ten_per_minute_returns_rate_limit_exceeded(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        for ($i = 0; $i < 10; $i++) {
            $endpoint = $i % 2 === 0 ? 'call-waiter' : 'bill';
            $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/{$endpoint}");
            // Some of these will 409 (already open) — that's fine, the
            // limiter counts requests regardless of outcome.
            $this->assertContains($response->status(), [201, 409]);
        }

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(429);

        $response->assertJson(['error' => ['code' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Too many requests.']]);
    }

    public function test_limiter_is_scoped_per_table_token_not_shared_with_orders(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter");
        }
        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")->assertStatus(429);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(201);
    }
}

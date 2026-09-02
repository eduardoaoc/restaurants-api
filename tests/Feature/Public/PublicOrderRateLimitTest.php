<?php

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicOrderRateLimitTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_exceeding_ten_per_minute_returns_rate_limit_exceeded(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload)->assertStatus(201);
        }

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload)
            ->assertStatus(429);

        $response->assertJson(['error' => ['code' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Too many requests.']]);
    }

    public function test_limiter_is_scoped_per_table_token_not_shared_with_menu(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        // Exhaust the public-menu limiter for this IP; the orders limiter is
        // a separate bucket and must be unaffected.
        for ($i = 0; $i < 60; $i++) {
            $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertOk();
        }
        $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertStatus(429);

        $this->postJson(
            "/api/v1/public/tables/{$table->public_token}/orders",
            ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]]
        )->assertStatus(201);
    }
}

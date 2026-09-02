<?php

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicRateLimitTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_exceeding_the_limit_returns_rate_limit_exceeded(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertOk();
        }

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertStatus(429);

        $response->assertJson([
            'error' => ['code' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Too many requests.'],
        ]);
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_the_menu_endpoint_shares_the_same_limiter_bucket_per_ip(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertOk();
        }
        for ($i = 0; $i < 30; $i++) {
            $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertStatus(404);
        }

        $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertStatus(429);
    }
}

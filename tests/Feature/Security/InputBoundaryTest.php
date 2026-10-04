<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class InputBoundaryTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    public static function malformedEmails(): array
    {
        return ['array' => [['x']], 'object' => [['email' => 'x']], 'long' => [str_repeat('x', 100000).'@example.test']];
    }

    #[DataProvider('malformedEmails')]
    public function test_login_rejects_malformed_input_without_server_error(mixed $email): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password123'])
            ->assertUnprocessable();
    }

    public function test_payment_rejects_integer_overflow_before_money_conversion(): void
    {
        $this->seedRolesAndPermissions();
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner);
        $this->withHeader('Origin', 'http://localhost:5173')->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '999999999999999999999999.00'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('payment_records', 0);
    }
}

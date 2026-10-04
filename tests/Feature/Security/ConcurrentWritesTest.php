<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ConcurrentWritesTest extends TestCase
{
    use DatabaseTruncation, InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants;

    private array $processes = [];

    private ?string $barrier = null;

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        if ($this->barrier !== null) {
            @unlink($this->barrier);
        }
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public static function operations(): array
    {
        return array_map(fn ($name) => [$name], ['order', 'payment', 'cash', 'open', 'close_session']);
    }

    #[DataProvider('operations')]
    public function test_two_real_database_connections_do_not_duplicate_the_same_operation(string $operation): void
    {
        $this->seedRolesAndPermissions();
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $operation === 'open' ? null : $this->openSession($table, $owner);
        if (in_array($operation, ['payment', 'close_session'], true)) {
            $this->createServedOrder($table, $owner);
        }
        if ($operation === 'close_session') {
            $this->recordPayment($session, $owner, '10.00');
        }
        $this->barrier = sys_get_temp_dir().'/security-race-'.bin2hex(random_bytes(12));
        $payload = match ($operation) {
            'order' => ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]], 'idempotency_key' => 'parallel-key'],
            'payment' => ['method' => 'cash', 'amount' => '5.00', 'idempotency_key' => 'parallel-key'],
            'cash' => ['type' => 'pay_in', 'amount' => '5.00', 'reason' => 'parallel', 'idempotency_key' => 'parallel-key'],
            default => [],
        };
        $args = ['operation' => $operation, 'user_id' => $owner->id, 'restaurant_id' => $restaurant->id, 'table_id' => $table->id, 'session_id' => $session?->id, 'token' => $table->public_token, 'payload' => $payload, 'barrier' => $this->barrier];
        for ($i = 0; $i < 2; $i++) {
            $process = new Process([PHP_BINARY, base_path('tests/Concurrency/worker.php'), 'security_race', json_encode($args)], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'),
                'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            ]);
            $process->setTimeout(30);
            $process->start();
            $this->processes[] = $process;
        }
        usleep(300000);
        touch($this->barrier);
        $results = [];
        foreach ($this->processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true);
            $this->assertArrayNotHasKey('exception', $result);
            $results[] = $result;
        }
        if (in_array($operation, ['open', 'close_session'], true)) {
            $outcomes = array_column($results, 'outcome');
            sort($outcomes);
            $this->assertSame(['accepted', 'refused'], $outcomes);
        } else {
            $this->assertSame($results[0]['id'], $results[1]['id']);
        }
        $tableName = match ($operation) {
            'order' => 'orders', 'payment' => 'payment_records', 'cash' => 'restaurant_cash_movements', default => 'table_sessions'
        };
        $this->assertSame(1, DB::table($tableName)->count());
        if ($operation === 'payment') {
            $this->assertSame('5.00', DB::table('payment_records')->value('amount'));
        }
        if ($operation === 'close_session') {
            $this->assertSame('closed', $session->fresh()->status);
        }
    }
}

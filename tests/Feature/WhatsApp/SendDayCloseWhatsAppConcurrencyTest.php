<?php

namespace Tests\Feature\WhatsApp;

use App\Actions\DayClose\BuildDayClosePreviewAction;
use App\Actions\DayClose\CloseRestaurantDayAction;
use App\Actions\WhatsApp\UpdateDayCloseWhatsAppSettingsAction;
use App\Models\RestaurantDayCloseDelivery;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWhatsApp;
use Tests\TestCase;

/**
 * CARTA 9.1E final validation — two REAL concurrent executions of the send
 * job for the same delivery (separate PHP processes, separate PostgreSQL
 * connections, committed data — see DayCloseConcurrencyTest for the
 * harness rationale). The fake provider holds the "HTTP call" for
 * HOLD_MS and appends one line per call to a file.
 */
class SendDayCloseWhatsAppConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, InteractsWithWhatsApp;

    private const HOLD_MS = 1500;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->configureWhatsApp();
        $this->dir = sys_get_temp_dir().'/wa-job-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function pendingDelivery(): RestaurantDayCloseDelivery
    {
        Queue::fake(); // the job only runs in the worker processes
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        app(UpdateDayCloseWhatsAppSettingsAction::class)->execute($restaurant->refresh(), $owner, [
            'enabled' => true,
            'recipient' => ['name' => 'Carlos', 'phone' => '+34612345678', 'consent_confirmed' => true],
        ]);
        sleep(1);
        $preview = app(BuildDayClosePreviewAction::class)->execute($restaurant->refresh());
        app(CloseRestaurantDayAction::class)->execute($restaurant->refresh(), $owner, [
            'idempotency_key' => 'wa-concurrency',
            'period_started_at' => $preview['period']['period_started_at'],
            'expected_cash_seen' => $preview['cash']['expected_cash'],
            'counted_cash' => $preview['cash']['expected_cash'],
        ]);

        return RestaurantDayCloseDelivery::query()->sole();
    }

    private function worker(int $deliveryId, string $barrier, int $delayMs = 0): Process
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Concurrency/worker.php'), 'whatsapp_job', json_encode([
                'delivery_id' => $deliveryId,
                'calls_file' => $this->dir.'/calls',
                'hold_ms' => self::HOLD_MS,
                'barrier' => $barrier,
                'delay_ms' => $delayMs,
            ])],
            base_path(),
            ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'],
        );
        $process->setTimeout(60);
        $process->start();

        return $process;
    }

    /**
     * @return array<string, mixed>
     */
    private function workerResult(Process $process): array
    {
        $process->wait();
        $result = json_decode($process->getOutput(), true);
        $this->assertIsArray($result, $process->getOutput().$process->getErrorOutput());
        $this->assertArrayNotHasKey('exception', $result, $result['exception'] ?? '');

        return $result;
    }

    private function providerCalls(): int
    {
        return file_exists($this->dir.'/calls') ? count(array_filter(explode("\n", (string) file_get_contents($this->dir.'/calls')))) : 0;
    }

    public function test_two_simultaneous_executions_send_exactly_once(): void
    {
        $delivery = $this->pendingDelivery();
        $barrier = $this->dir.'/go';

        $a = $this->worker($delivery->id, $barrier);
        $b = $this->worker($delivery->id, $barrier);
        usleep(400 * 1000);
        touch($barrier);
        $this->workerResult($a);
        $this->workerResult($b);

        $this->assertSame(1, $this->providerCalls());
        $delivery->refresh();
        $this->assertSame('accepted', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringStartsWith('wamid.', $delivery->provider_message_id);
        $this->assertNull($delivery->sending_started_at);
    }

    public function test_an_execution_arriving_mid_call_exits_fast_without_waiting_for_a_row_lock(): void
    {
        $delivery = $this->pendingDelivery();
        $barrier = $this->dir.'/go';

        $first = $this->worker($delivery->id, $barrier);
        // Starts while the first one is inside its HTTP call (held HOLD_MS).
        $second = $this->worker($delivery->id, $barrier, delayMs: 300);
        usleep(400 * 1000);
        touch($barrier);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        $this->assertSame(1, $this->providerCalls());
        // If the first run held the row lock during HTTP, the second would
        // block until it committed
        // (~HOLD_MS - 300ms ≈ 1.2s). It returns almost immediately instead.
        $this->assertLessThan(400, $secondResult['finished_at_ms'] - $secondResult['started_at_ms'], 'the second run must not wait on a row lock held across the HTTP call');
        $this->assertLessThan($firstResult['finished_at_ms'], $secondResult['finished_at_ms']);
        $this->assertSame('accepted', $delivery->refresh()->status);
    }
}

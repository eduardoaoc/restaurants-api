<?php

namespace Tests\Feature\DayClose;

use App\Actions\DayClose\BuildDayClosePreviewAction;
use App\Actions\DayClose\CloseRestaurantDayAction;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantCashMovement;
use App\Models\RestaurantDayClose;
use App\Models\User;
use App\Support\Restaurants\RestaurantOperationalLock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 9.1A — the operational lock under REAL concurrency: every worker
 * is a separate PHP process (tests/Concurrency/worker.php) with its own
 * PostgreSQL connection, against committed data (DatabaseTruncation, not
 * RefreshDatabase — a transaction-wrapped test would be invisible to the
 * workers). tearDown truncates again so the transactional tests that run
 * after this class start from an empty database.
 *
 * Uses the real clock (workers can't share travelTo()).
 */
class DayCloseConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants;

    private const HOLD_MS = 1500;

    private string $barrierDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->barrierDir = sys_get_temp_dir().'/dayclose-'.uniqid();
        mkdir($this->barrierDir);
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        array_map('unlink', glob($this->barrierDir.'/*') ?: []);
        rmdir($this->barrierDir);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function worker(string $scenario, array $args): Process
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Concurrency/worker.php'), $scenario, json_encode($args)],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => config('database.connections.pgsql.database'),
                'BROADCAST_CONNECTION' => 'null',
                'QUEUE_CONNECTION' => 'sync',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
            ],
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
        $this->assertIsArray($result, 'worker output: '.$process->getOutput().$process->getErrorOutput());
        $this->assertArrayNotHasKey('exception', $result, $result['exception'] ?? '');

        return $result;
    }

    private function waitForFile(string $file): void
    {
        $deadline = microtime(true) + 15;
        while (! file_exists($file)) {
            $this->assertLessThan($deadline, microtime(true), "worker never signalled {$file}");
            usleep(5000);
        }
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * Holds the EXCLUSIVE operational lock (what a close holds during its
     * critical section) on this process's connection for HOLD_MS.
     */
    private function holdExclusiveLock(Restaurant $restaurant, callable $whileHeld): CarbonImmutable
    {
        DB::beginTransaction();
        RestaurantOperationalLock::exclusive($restaurant->id);
        $cutoff = CarbonImmutable::now('UTC')->startOfSecond();
        $whileHeld();
        usleep(self::HOLD_MS * 1000);
        DB::commit();

        return $cutoff;
    }

    /**
     * @return array{0: Restaurant, 1: User}
     */
    private function restaurant(): array
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');

        return [$restaurant->refresh(), $owner];
    }

    private function closePayload(Restaurant $restaurant, string $key): array
    {
        $preview = app(BuildDayClosePreviewAction::class)->execute($restaurant->refresh());

        return [
            'idempotency_key' => $key,
            'period_started_at' => $preview['period']['period_started_at'],
            'expected_cash_seen' => $preview['cash']['expected_cash'],
            'counted_cash' => $preview['cash']['expected_cash'],
        ];
    }

    /**
     * A. A payment (and the table close it enables) is in flight when the
     * close starts: the close waits for it, and both belong to THIS close.
     * (Any active session blocks the close, so the realistic in-flight
     * writer is the waiter's "pay + close table".)
     */
    public function test_in_flight_payment_and_table_close_belong_to_the_close(): void
    {
        [$restaurant, $owner] = $this->restaurant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner); // 10.00 due

        $signal = $this->barrierDir.'/paid';
        $worker = $this->worker('hold_shared_pay_and_close', ['session_id' => $session->id, 'user_id' => $owner->id, 'amount' => '10.00', 'signal_file' => $signal, 'hold_ms' => self::HOLD_MS]);
        $this->waitForFile($signal);
        sleep(1); // T (second precision) strictly after the payment's recorded_at

        // The client counted against the post-payment state.
        $payload = [
            'idempotency_key' => 'close-A2',
            'period_started_at' => app(BuildDayClosePreviewAction::class)->execute($restaurant)['period']['period_started_at'],
            'expected_cash_seen' => '10.00',
            'counted_cash' => '10.00',
        ];

        $started = $this->nowMs();
        $close = app(CloseRestaurantDayAction::class)->execute($restaurant->refresh(), $owner, $payload)['day_close'];
        $elapsed = $this->nowMs() - $started;
        $result = $this->workerResult($worker);

        $this->assertGreaterThanOrEqual(400, $elapsed, 'the close must have waited for the in-flight writer');
        $this->assertSame('10.00', $close->total_received);
        $this->assertSame('10.00', $close->cash_received);
        $this->assertSame(1, $close->sessions_closed);
        $this->assertTrue(CarbonImmutable::parse($result['recorded_at'], 'UTC')->lessThan($close->period_ended_at));
    }

    /**
     * B. The close holds the exclusive lock first: a payment arriving
     * meanwhile waits, and its recorded_at is at/after the cutoff T — it
     * can only ever belong to the NEXT period.
     */
    public function test_payment_waits_for_the_close_and_lands_after_the_cutoff(): void
    {
        [$restaurant, $owner] = $this->restaurant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner);

        $worker = null;
        $released = 0;
        $cutoff = $this->holdExclusiveLock($restaurant, function () use (&$worker, $session, $owner) {
            $worker = $this->worker('payment', ['session_id' => $session->id, 'user_id' => $owner->id, 'amount' => '10.00']);
        });
        $released = $this->nowMs();
        $result = $this->workerResult($worker);

        $this->assertGreaterThanOrEqual($released - 100, $result['finished_at_ms'], 'the payment can only finish after the lock is released');
        $this->assertGreaterThanOrEqual(self::HOLD_MS - 600, $result['finished_at_ms'] - $result['started_at_ms']);
        $this->assertTrue(CarbonImmutable::parse($result['recorded_at'], 'UTC')->greaterThanOrEqualTo($cutoff));
    }

    /**
     * B (full). A real close completes while a cash writer waits: the
     * writer's row is excluded from the close and lands in the next period.
     */
    public function test_writer_blocked_by_a_real_close_belongs_to_the_next_period(): void
    {
        [$restaurant, $owner] = $this->restaurant();
        $payload = $this->closePayload($restaurant, 'close-B');
        sleep(1);

        $worker = null;
        DB::beginTransaction();
        $close = app(CloseRestaurantDayAction::class)->execute($restaurant, $owner, $payload)['day_close'];
        $worker = $this->worker('cash_movement', ['restaurant_id' => $restaurant->id, 'user_id' => $owner->id, 'amount' => '20.00', 'key' => 'mv-1']);
        usleep(self::HOLD_MS * 1000);
        DB::commit();
        $result = $this->workerResult($worker);

        $movement = RestaurantCashMovement::query()->findOrFail($result['movement_id']);
        $this->assertSame('0.00', $close->refresh()->cash_pay_ins);
        $this->assertTrue($movement->recorded_at->greaterThanOrEqualTo($close->period_ended_at));

        sleep(1);
        $this->assertSame('20.00', app(BuildDayClosePreviewAction::class)->execute($restaurant->refresh())['cash']['cash_pay_ins']);
    }

    /**
     * C. Two closes at the same instant (different keys): exactly one is
     * created; the other is refused (period already moved).
     */
    public function test_two_parallel_closes_create_exactly_one(): void
    {
        [$restaurant, $owner] = $this->restaurant();
        sleep(1);
        $payloadA = $this->closePayload($restaurant, 'parallel-A');
        $payloadB = [...$payloadA, 'idempotency_key' => 'parallel-B'];
        $barrier = $this->barrierDir.'/go';

        $a = $this->worker('close', ['restaurant_id' => $restaurant->id, 'user_id' => $owner->id, 'payload' => $payloadA, 'barrier' => $barrier]);
        $b = $this->worker('close', ['restaurant_id' => $restaurant->id, 'user_id' => $owner->id, 'payload' => $payloadB, 'barrier' => $barrier]);
        usleep(300 * 1000);
        touch($barrier);

        $outcomes = [$this->workerResult($a), $this->workerResult($b)];
        $created = array_values(array_filter($outcomes, fn ($r) => ($r['outcome'] ?? null) === 'created'));
        $refused = array_values(array_filter($outcomes, fn ($r) => ($r['outcome'] ?? null) === 'error'));

        $this->assertCount(1, $created);
        $this->assertCount(1, $refused);
        $this->assertContains($refused[0]['error_code'], ['PERIOD_CHANGED', 'BUSINESS_DATE_ALREADY_CLOSED']);
        $this->assertSame(1, RestaurantDayClose::query()->count());
    }

    /**
     * C'. The same Idempotency-Key fired twice in parallel (double click
     * on two connections): one close, the other request replays it.
     */
    public function test_parallel_requests_with_the_same_key_replay(): void
    {
        [$restaurant, $owner] = $this->restaurant();
        sleep(1);
        $payload = $this->closePayload($restaurant, 'same-key');
        $barrier = $this->barrierDir.'/go';

        $a = $this->worker('close', ['restaurant_id' => $restaurant->id, 'user_id' => $owner->id, 'payload' => $payload, 'barrier' => $barrier]);
        $b = $this->worker('close', ['restaurant_id' => $restaurant->id, 'user_id' => $owner->id, 'payload' => $payload, 'barrier' => $barrier]);
        usleep(300 * 1000);
        touch($barrier);

        $outcomes = [$this->workerResult($a)['outcome'], $this->workerResult($b)['outcome']];
        sort($outcomes);
        $this->assertSame(['created', 'replayed'], $outcomes);
        $this->assertSame(1, RestaurantDayClose::query()->count());
    }

    /**
     * D. An order transition waits for a close holding the lock, then
     * completes normally.
     */
    public function test_order_transition_waits_for_the_close(): void
    {
        [$restaurant, $owner] = $this->restaurant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createServedOrder($table, $owner);
        $pending = $this->advanceOrderTo(
            $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $order->items()->value('restaurant_product_id'), 'quantity' => 1]]),
            Order::STATUS_ACCEPTED,
            $owner,
        );

        $worker = null;
        $this->holdExclusiveLock($restaurant, function () use (&$worker, $pending, $owner) {
            $worker = $this->worker('transition', ['order_id' => $pending->id, 'user_id' => $owner->id, 'method' => 'startPreparing']);
        });
        $result = $this->workerResult($worker);

        $this->assertSame(Order::STATUS_PREPARING, $result['status']);
        $this->assertGreaterThanOrEqual(self::HOLD_MS - 600, $result['finished_at_ms'] - $result['started_at_ms']);
        $this->assertSame(Order::STATUS_PREPARING, $pending->refresh()->status);
    }

    /**
     * E. Writers (operational lock, then row lock — the mandated order)
     * and closes hammering one restaurant concurrently never deadlock.
     */
    public function test_mixed_writers_and_closes_do_not_deadlock(): void
    {
        [$restaurant, $owner] = $this->restaurant();
        $sessionA = $this->openSession($this->createTable($restaurant), $owner);
        $sessionB = $this->openSession($this->createTable($restaurant), $owner);
        sleep(1);
        $payload = $this->closePayload($restaurant, 'unused');
        unset($payload['idempotency_key']);
        $barrier = $this->barrierDir.'/go';

        $workers = [
            $this->worker('stress', ['kind' => 'writer', 'session_id' => $sessionA->id, 'duration_ms' => 2500, 'barrier' => $barrier]),
            $this->worker('stress', ['kind' => 'writer', 'session_id' => $sessionA->id, 'duration_ms' => 2500, 'barrier' => $barrier]),
            $this->worker('stress', ['kind' => 'writer', 'session_id' => $sessionB->id, 'duration_ms' => 2500, 'barrier' => $barrier]),
            $this->worker('stress', ['kind' => 'close', 'restaurant_id' => $restaurant->id, 'user_id' => $owner->id, 'payload' => $payload, 'duration_ms' => 2500, 'barrier' => $barrier]),
            $this->worker('stress', ['kind' => 'close', 'restaurant_id' => $restaurant->id, 'user_id' => $owner->id, 'payload' => $payload, 'duration_ms' => 2500, 'barrier' => $barrier]),
        ];
        usleep(300 * 1000);
        touch($barrier);

        foreach ($workers as $worker) {
            $result = $this->workerResult($worker);
            $this->assertSame([], $result['errors'], implode("\n", $result['errors']));
            $this->assertGreaterThan(0, $result['operations']);
        }

        $this->assertSame(0, RestaurantDayClose::query()->count(), 'active sessions block every close attempt');
    }
}

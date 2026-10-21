<?php

namespace Tests\Feature\DailyOperation;

use App\Actions\DayClose\BuildDayClosePreviewAction;
use App\Actions\DayClose\CloseRestaurantDayAction;
use App\Models\RestaurantDailyOperation;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Real overlap between independent PHP processes (each its own PostgreSQL
 * connection): the worker must actually wait on the operational lock.
 */
class DailyOperationConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithOrders, InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withoutDailyOperationFixture();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function worker(string $mode, int $restaurantId, int $userId, string $arg): array
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Support/daily-operation-worker.php'), $mode, (string) $restaurantId, (string) $userId, $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);

        return [$process, $pipes];
    }

    private function assertWorkerWaiting(): void
    {
        $deadline = microtime(true) + 10;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->where('datname', DB::connection()->getDatabaseName())->where('wait_event_type', 'Lock')->count();
            if ($waiting > 0) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->assertGreaterThan(0, $waiting, 'Independent worker must actually wait on PostgreSQL');
    }

    private function workerResult(array $worker): array
    {
        [$process, $pipes] = $worker;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);

        return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    }

    private function close($restaurant, $owner, string $key): void
    {
        $preview = app(BuildDayClosePreviewAction::class)->execute($restaurant);
        app(CloseRestaurantDayAction::class)->execute($restaurant, $owner, [
            'idempotency_key' => $key, 'period_started_at' => $preview['period']['period_started_at'],
            'expected_cash_seen' => $preview['cash']['expected_cash'], 'counted_cash' => $preview['cash']['expected_cash'],
        ]);
    }

    public function test_table_opening_waiting_on_a_close_is_refused_after_it_commits(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->load('settings');
        $this->openDay($restaurant, $owner);
        $table = $this->createTable($restaurant);

        DB::beginTransaction();
        try {
            $this->close($restaurant, $owner, 'close-1');
            $worker = $this->worker('open-table', $restaurant->id, $owner->id, (string) $table->id);
            $this->assertWorkerWaiting();
        } finally {
            DB::commit();
        }

        $this->assertSame(['code' => 'RESTAURANT_DAY_NOT_OPEN'], $this->workerResult($worker));
        $this->assertSame(0, TableSession::count());
        $this->assertSame(RestaurantDailyOperation::STATUS_CLOSED, RestaurantDailyOperation::sole()->status);
    }

    public function test_a_table_opened_first_blocks_the_waiting_close(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->load('settings');
        $operation = $this->openDay($restaurant, $owner);
        $table = $this->createTable($restaurant);
        $preview = app(BuildDayClosePreviewAction::class)->execute($restaurant);

        DB::beginTransaction();
        try {
            $session = $this->openSession($table, $owner);
            $process = proc_open([PHP_BINARY, '-r', sprintf(
                'require "vendor/autoload.php"; $a = require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
                .' try { app(App\Actions\DayClose\CloseRestaurantDayAction::class)->execute(App\Models\Restaurant::with("settings")->find(%d), App\Models\User::find(%d), ["idempotency_key" => "late", "period_started_at" => "%s", "expected_cash_seen" => "%s", "counted_cash" => "%s"]); echo "closed"; }'
                .' catch (App\Exceptions\DayClose\DayCloseException $e) { echo $e->errorCode; }',
                $restaurant->id, $owner->id, $preview['period']['period_started_at'], $preview['cash']['expected_cash'], $preview['cash']['expected_cash'],
            )], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertWorkerWaiting();
        } finally {
            DB::commit();
        }

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);
        $this->assertSame('CLOSE_BLOCKED', $out);
        $this->assertSame($operation->id, $session->daily_operation_id);
        $this->assertTrue($operation->fresh()->isOpen());
    }

    public function test_two_simultaneous_openers_create_one_day(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $restaurant->load('settings');
        $manager = $this->createStaff($org, $restaurant, 'manager', 'M1');

        DB::beginTransaction();
        try {
            $mine = $this->openDay($restaurant, $owner, '10.00', 'same-key');
            $other = $this->worker('open-day', $restaurant->id, $manager->id, 'other-key');
            $this->assertWorkerWaiting();
        } finally {
            DB::commit();
        }
        $this->assertSame(['code' => 'DAILY_OPERATION_ALREADY_OPEN'], $this->workerResult($other));

        $retry = $this->worker('open-day', $restaurant->id, $owner->id, 'same-key');
        $this->assertSame(['id' => $mine->public_id, 'replayed' => true], $this->workerResult($retry));
        $this->assertSame(1, RestaurantDailyOperation::count());
    }
}

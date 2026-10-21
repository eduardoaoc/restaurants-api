<?php

namespace Tests\Feature\Public;

use App\Actions\Staff\StartStaffShiftAction;
use App\Actions\Tables\OpenTableAction;
use App\Events\Realtime\RestaurantActivityCreated;
use App\Events\Realtime\TableSessionOpened;
use App\Models\AuditLog;
use App\Models\TableSession;
use App\Support\Activity\RestaurantActivityType;
use App\Support\Tables\WaiterAssignmentLock;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicTableEntryConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        // These processes commit real fixture rows. Leave the isolated test
        // database empty for subsequent RefreshDatabase test classes.
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_concurrent_customer_and_staff_entries_open_once_after_commit(): void
    {
        $this->seedRolesAndPermissions();
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        // Hold the exact shared entry row until all processes are running.
        DB::beginTransaction();
        DB::table('tables')->where('id', $table->id)->lockForUpdate()->first();
        $workers = [];
        try {
            foreach (['customer', 'customer', 'staff', 'customer', 'staff', 'customer'] as $type) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/public-table-entry-worker.php'), $table->public_token, $type, (string) $owner->id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $type];
            }
            // Prove that at least two independent connections actually wait
            // on locks, rather than treating sequential retries as concurrency.
            $waiting = 0;
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('datname', DB::connection()->getDatabaseName())->where('wait_event_type', 'Lock')->count();
                if ($waiting >= 2) {
                    break;
                }
                usleep(20000);
            }

        } finally {
            DB::commit();
        }
        $ids = [];
        foreach ($workers as [$process, $pipes, $type]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            if ($type === 'customer') {
                $this->assertArrayHasKey('id', $result);
            }
            if (isset($result['id'])) {
                $ids[] = $result['id'];
            }
        }
        $this->assertGreaterThanOrEqual(2, $waiting);
        $this->assertCount(1, array_unique($ids));
        $this->assertDatabaseCount('table_sessions', 1);
        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_OPENED)->count());
        $this->assertSame(1, DB::table('restaurant_activity_events')->where('type', RestaurantActivityType::TABLE_SESSION_OPENED)->where('table_session_id', TableSession::query()->sole()->id)->count());

        // The real event dispatcher defers listeners until commit; reuse
        // emits nothing, even when invoked again after the first commit.
        $events = 0;
        Event::listen(TableSessionOpened::class, function () use (&$events) {
            $events++;
        });
        $otherTable = $this->createTable($restaurant);
        DB::beginTransaction();
        app(OpenTableAction::class)->execute($otherTable, null, 0, reuseActive: true);
        $this->assertSame(0, $events);
        DB::commit();
        $this->assertSame(1, $events);
        app(OpenTableAction::class)->execute($otherTable, null, 0, reuseActive: true);
        $this->assertSame(1, $events);
    }

    public function test_concurrent_different_tables_balance_demand_and_assignment_events_wait_for_commit(): void
    {
        $this->seedRolesAndPermissions();
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiters = [];
        foreach (['W1', 'W2'] as $subId) {
            $waiter = $this->createStaff($organization, $restaurant, 'waiter', $subId);
            app(StartStaffShiftAction::class)->execute($restaurant, $waiter, $waiter);
            $waiters[] = $waiter;
        }
        $tables = [];
        foreach (range(1, 4) as $i) {
            $tables[] = $this->createTable($restaurant);
        }
        DB::beginTransaction();
        WaiterAssignmentLock::acquire($restaurant->id);
        $workers = [];
        try {
            foreach ($tables as $table) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/public-table-entry-worker.php'), $table->public_token, 'customer', (string) $owner->id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            $waiting = 0;
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('datname', DB::connection()->getDatabaseName())->where('wait_event_type', 'Lock')->count();
                if ($waiting >= 4) {
                    break;
                }
                usleep(20000);
            }
        } finally {
            DB::commit();
        }
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $this->assertArrayHasKey('id', json_decode($output, true, flags: JSON_THROW_ON_ERROR));
        }
        $this->assertGreaterThanOrEqual(4, $waiting, 'Prove overlapping independent PostgreSQL connections');
        foreach ($waiters as $waiter) {
            $this->assertSame(2, TableSession::query()->where('assigned_waiter_user_id', $waiter->id)->count());
        }
        $this->assertSame(4, DB::table('restaurant_activity_events')->where('type', RestaurantActivityType::TABLE_WAITER_ASSIGNED)->count());
        $delivered = 0;
        Event::listen(RestaurantActivityCreated::class, function ($event) use (&$delivered) {
            if ($event->activity['type'] === RestaurantActivityType::TABLE_WAITER_ASSIGNED) {
                $delivered++;
            }
        });
        $table = $this->createTable($restaurant);
        DB::beginTransaction();
        app(OpenTableAction::class)->execute($table, null, 0);
        $this->assertSame(0, $delivered);
        DB::rollBack();
        $this->assertSame(0, $delivered);
        $this->assertSame(4, TableSession::query()->count());
        DB::beginTransaction();
        app(OpenTableAction::class)->execute($table, null, 0);
        $this->assertSame(0, $delivered);
        DB::commit();
        $this->assertSame(1, $delivered);
    }
}

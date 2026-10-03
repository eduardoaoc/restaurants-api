<?php

namespace Tests\Feature\Realtime;

use App\Actions\Tables\OpenTableAction;
use App\Events\Realtime\TableSessionOpened;
use App\Exceptions\TableSessionConflictException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 7 — proves the ShouldDispatchAfterCommit contract every
 * RealtimeEvent relies on (item 44): a rolled-back transaction must never
 * broadcast, and a committed one always does. Uses a real Event::listen()
 * spy rather than Event::fake() — a fake intercepts dispatch immediately
 * and would never exercise the real commit-deferral behavior at all (see
 * RealtimeEvent's own docblock).
 */
class AfterCommitBroadcastTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_event_listener_never_runs_when_the_enclosing_transaction_rolls_back(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $fired = false;
        Event::listen(TableSessionOpened::class, function () use (&$fired) {
            $fired = true;
        });

        try {
            DB::transaction(function () use ($restaurant, $table) {
                TableSessionOpened::dispatch($restaurant->id, $table->id, 1, 2, now());

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse($fired, 'A realtime event listener ran even though its transaction rolled back.');
    }

    public function test_event_listener_runs_once_the_transaction_actually_commits(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $fired = false;
        Event::listen(TableSessionOpened::class, function () use (&$fired) {
            $fired = true;
        });

        DB::transaction(function () use ($restaurant, $table) {
            TableSessionOpened::dispatch($restaurant->id, $table->id, 1, 2, now());
        });

        $this->assertTrue($fired, 'A realtime event listener never ran even though its transaction committed.');
    }

    /**
     * Confirmatory test on a real domain Action: the second, conflicting
     * open attempt fails its precondition check BEFORE the dispatch line
     * is ever reached — exactly one TableSessionOpened is broadcast, never
     * two, and never one for the failed attempt.
     */
    public function test_a_failed_action_never_broadcasts_even_once(): void
    {
        Event::fake([TableSessionOpened::class]);

        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        app(OpenTableAction::class)->execute($table, $owner, 2);

        try {
            app(OpenTableAction::class)->execute($table, $owner, 2);
            $this->fail('Expected a TableSessionConflictException.');
        } catch (TableSessionConflictException) {
            // expected
        }

        Event::assertDispatchedTimes(TableSessionOpened::class, 1);
    }
}

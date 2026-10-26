<?php

namespace Tests\Feature\Invitations;

use App\Actions\Invitations\AcceptInvitationAction;
use App\Actions\Invitations\RevokeInvitationAction;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithInvitations;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Real overlap between independent PHP processes (each its own PostgreSQL
 * connection): the worker must actually wait on the invitation row lock.
 */
class InvitationConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithInvitations, InteractsWithPlatform, InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->seedPlatformRolesAndPermissions();
        config(['invitations.web_url' => 'http://localhost:5173']);
        Notification::fake();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function worker(string $mode, string $token, string $password): array
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Support/invitation-accept-worker.php'), $mode, $token, $password], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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

    private function staffInvitationToken(): array
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->actingAs($owner, 'web')->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/staff/invitations', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'waiter',
            'restaurant_assignments' => [['restaurant_id' => $restaurant->id, 'sub_id' => 'W-1']],
        ])->assertCreated();
        $invitation = Invitation::query()->sole();

        return [$invitation, $this->mintToken($invitation), $organization];
    }

    public function test_two_simultaneous_accepts_produce_exactly_one_account_and_one_grant(): void
    {
        [$invitation, $token, $organization] = $this->staffInvitationToken();

        DB::beginTransaction();
        try {
            $mine = app(AcceptInvitationAction::class)->execute($token, AcceptInvitationAction::MODE_NEW, ['name' => 'First', 'password' => 'first-pass-1'], null);
            $other = $this->worker(AcceptInvitationAction::MODE_NEW, $token, 'second-pass-1');
            $this->assertWorkerWaiting();
        } finally {
            DB::commit();
        }

        // The loser waited on the row lock, then saw the final state.
        $this->assertSame(['code' => 'INVITATION_ALREADY_ACCEPTED'], $this->workerResult($other));
        $this->assertSame(1, User::query()->where('email', 'ana@example.com')->count());
        $this->assertSame($mine['user']->id, $invitation->fresh()->accepted_user_id);
        $this->assertSame(1, OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $mine['user']->id)->count());
        $this->assertSame(1, UserRole::query()->where('user_id', $mine['user']->id)->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_INVITATION_ACCEPTED)->count());
    }

    public function test_revoke_during_acceptance_wins_or_loses_atomically(): void
    {
        [$invitation, $token] = $this->staffInvitationToken();
        $owner = User::query()->whereKey($invitation->invited_by_user_id)->sole();

        DB::beginTransaction();
        try {
            app(RevokeInvitationAction::class)->execute($invitation, $owner, AuditLog::ACTOR_USER);
            $accept = $this->worker(AcceptInvitationAction::MODE_NEW, $token, 'some-pass-1');
            $this->assertWorkerWaiting();
        } finally {
            DB::commit();
        }

        $this->assertSame(['code' => 'INVITATION_REVOKED'], $this->workerResult($accept));
        $this->assertSame(0, User::query()->where('email', 'ana@example.com')->count());
        $this->assertSame(Invitation::STATUS_REVOKED, $invitation->fresh()->status);
    }
}

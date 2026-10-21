<?php

namespace Tests\Feature\TableSession;

use App\Actions\Public\EnterPublicTableAction;
use App\Actions\Staff\StartStaffShiftAction;
use App\Actions\Staff\UpdateStaffAction;
use App\Actions\Tables\AssignWaiterAction;
use App\Actions\Tables\OpenTableAction;
use App\Actions\Tables\TransferTableSessionAction;
use App\Actions\Tables\VoidEmptyTableSessionAction;
use App\Events\Realtime\RestaurantActivityCreated;
use App\Exceptions\Tables\WaiterAssignmentIneligibleException;
use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantActivityEvent;
use App\Models\User;
use App\Support\Activity\RestaurantActivityType as Type;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AutomaticWaiterAssignmentTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function as(User $user): static
    {
        Auth::forgetGuards();
        $this->app['session']->driver()->flush();

        return $this->actingAs($user, 'web');
    }

    private function onShift(Restaurant $restaurant, User $waiter): void
    {
        app(StartStaffShiftAction::class)->execute($restaurant, $waiter, $waiter);
    }

    private function assignmentCount(): int
    {
        return RestaurantActivityEvent::query()->where('type', Type::TABLE_WAITER_ASSIGNED)->count();
    }

    public function test_waiter_opening_is_assigned_and_consultation_does_not_steal(): void
    {
        [$org, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        $other = $this->createStaff($org, $restaurant, 'waiter', 'W2');
        $table = $this->createTable($restaurant);
        $this->as($waiter)->postJson("/api/v1/tables/{$table->id}/open", ['guest_count' => 3])
            ->assertCreated()->assertJsonPath('data.session.assigned_waiter.id', $waiter->id);
        $this->as($other)->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")->assertOk();
        $this->assertSame($waiter->id, $table->activeSession()->sole()->assigned_waiter_user_id);
        $audit = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_WAITER_ASSIGNED)->sole();
        $this->assertSame('staff_open', $audit->metadata['source']);
        $this->assertSame(1, $this->assignmentCount());
    }

    public function test_owner_open_uses_distribution_and_is_never_implicitly_waiter(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        $this->onShift($restaurant, $waiter);
        $session = app(OpenTableAction::class)->execute($this->createTable($restaurant), $owner, 2);
        $this->assertSame($waiter->id, $session->assigned_waiter_user_id);
    }

    public function test_qr_keeps_valid_explicit_assignment_even_off_shift(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = app(OpenTableAction::class)->execute($table, $owner, 2);
        $waiter = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        $this->postJson("/api/v1/public/tables/{$table->public_token}/enter")->assertOk();
        $this->assertSame($waiter->id, $session->fresh()->assigned_waiter_user_id);
        $this->assertSame(1, $this->assignmentCount());
    }

    public function test_least_demand_then_never_assigned_then_oldest_assignment_and_id(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $a = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        $b = $this->createStaff($org, $restaurant, 'waiter', 'W2');
        $this->onShift($restaurant, $a);
        $this->onShift($restaurant, $b);
        $one = app(OpenTableAction::class)->execute($this->createTable($restaurant), $owner, 2);
        $two = app(OpenTableAction::class)->execute($this->createTable($restaurant), $owner, 2);
        $this->assertSame($a->id, $one->assigned_waiter_user_id);
        $this->assertSame($b->id, $two->assigned_waiter_user_id);
        // Reliable existing audit timestamps, not fabricated shift/login times.
        DB::table('audit_logs')->where('event', AuditLog::EVENT_TABLE_SESSION_WAITER_ASSIGNED)
            ->where('resource_id', $two->id)->update(['created_at' => now()->subHour()]);
        $three = app(OpenTableAction::class)->execute($this->createTable($restaurant), $owner, 2);
        $this->assertSame($b->id, $three->assigned_waiter_user_id);
        $four = app(OpenTableAction::class)->execute($this->createTable($restaurant), $owner, 2);
        $this->assertSame($a->id, $four->assigned_waiter_user_id);
    }

    public function test_inactive_suspended_foreign_restaurant_and_off_shift_are_excluded(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $inactive = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        $suspended = $this->createStaff($org, $restaurant, 'waiter', 'W2');
        $offShift = $this->createStaff($org, $restaurant, 'waiter', 'W3');
        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $foreign = $this->createStaff($org, $otherRestaurant, 'waiter', 'W4');
        $valid = $this->createStaff($org, $restaurant, 'waiter', 'W5');
        foreach ([$inactive, $suspended, $valid] as $user) {
            $this->onShift($restaurant, $user);
        }
        $this->onShift($otherRestaurant, $foreign);
        $inactive->organizations()->updateExistingPivot($org->id, ['status' => 'inactive']);
        $suspended->update(['status' => User::STATUS_SUSPENDED]);
        $session = app(OpenTableAction::class)->execute($this->createTable($restaurant), $owner, 2);
        $this->assertSame($valid->id, $session->assigned_waiter_user_id);
    }

    public function test_repeated_entry_get_and_polling_do_not_duplicate_and_public_has_no_staff_data(): void
    {
        [$org, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($org, $restaurant, 'waiter', 'W1', name: 'Private Waiter Name');
        $this->onShift($restaurant, $waiter);
        $table = $this->createTable($restaurant);
        $url = "/api/v1/public/tables/{$table->public_token}";
        $first = $this->postJson("$url/enter", ['user_id' => 999999, 'restaurant_id' => 999999])->assertOk();
        $token = $first->json('data.session.feedback.token');
        $this->postJson("$url/enter")->assertOk();
        $this->postJson("$url/enter", [], ['X-Table-Visit' => $token])->assertOk();
        for ($i = 0; $i < 3; $i++) {
            $this->getJson($url)->assertOk();
        }
        $this->assertStringNotContainsString($waiter->name, $first->getContent());
        $this->assertStringNotContainsString($waiter->email, $first->getContent());
        $this->assertSame(1, $this->assignmentCount());
        $this->assertSame($waiter->id, $table->activeSession()->sole()->assigned_waiter_user_id);
    }

    public function test_pending_is_durable_with_no_manager_and_reachable_by_later_authorized_manager(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $owner->organizations()->updateExistingPivot($org->id, ['status' => 'inactive']);
        $table = $this->createTable($restaurant);
        app(EnterPublicTableAction::class)->execute($table->public_token, null);
        app(EnterPublicTableAction::class)->execute($table->public_token, null);
        $this->assertNull($table->activeSession()->sole()->assigned_waiter_user_id);
        $this->assertSame(1, RestaurantActivityEvent::query()->where('type', Type::TABLE_ASSIGNMENT_PENDING)->count());
        $manager = $this->createStaff($org, $restaurant, 'manager', 'M1');
        $this->as($manager)->getJson("/api/v1/restaurants/{$restaurant->id}/activity?scope=assignments")
            ->assertOk()->assertJsonCount(1, 'data.activity')->assertJsonPath('data.activity.0.type', Type::TABLE_ASSIGNMENT_PENDING);
        $snapshot = $this->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")->assertOk();
        $this->assertContains('active_table_unassigned', array_column($snapshot->json('data.alerts'), 'type'));
    }

    public function test_notification_survives_reconnect_is_recipient_scoped_and_can_be_marked_read(): void
    {
        [$org, , $restaurant] = $this->createTenant();
        $a = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        $b = $this->createStaff($org, $restaurant, 'waiter', 'W2');
        $this->onShift($restaurant, $a);
        $table = $this->createTable($restaurant);
        app(EnterPublicTableAction::class)->execute($table->public_token, null);
        $url = "/api/v1/restaurants/{$restaurant->id}/activity";
        $item = $this->as($a)->getJson("$url?scope=assignments")->assertOk()->assertJsonCount(1, 'data.activity')->json('data.activity.0');
        $this->getJson("$url?scope=assignments")->assertOk()->assertJsonPath('meta.unread_count', 1);
        $this->getJson($url)->assertForbidden();
        $this->postJson("$url/read?scope=assignments", ['last_event_id' => $item['id']])->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->getJson("$url?scope=assignments")->assertOk()->assertJsonCount(1, 'data.activity');
        $this->as($b)->getJson("$url?scope=assignments")->assertOk()->assertJsonCount(0, 'data.activity')->assertJsonPath('meta.unread_count', 0);
        $this->postJson("$url/read?scope=assignments", ['last_event_id' => $item['id']])->assertUnprocessable();
        $event = RestaurantActivityEvent::query()->whereKey($item['id'])->sole();
        $channels = array_map(fn ($channel) => $channel->name, (new RestaurantActivityCreated($event))->broadcastOn());
        $this->assertContains("private-user.{$a->id}", $channels);
        $this->assertNotContains("private-user.{$b->id}", $channels);
    }

    public function test_manual_reassignment_authorization_and_tenant_isolation(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $a = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        $b = $this->createStaff($org, $restaurant, 'waiter', 'W2');
        $table = $this->createTable($restaurant);
        $session = app(OpenTableAction::class)->execute($table, $a, 2);
        $this->as($a)->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $b->id])->assertForbidden();
        $this->as($owner)->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $b->id])->assertOk();
        [, $foreign, $foreignRestaurant] = $this->createTenant();
        $this->as($foreign)->getJson("/api/v1/restaurants/{$restaurant->id}/activity?scope=assignments")->assertNotFound();
        $this->as($a)->getJson("/api/v1/restaurants/{$foreignRestaurant->id}/activity?scope=assignments")->assertNotFound();
        $this->as($foreign)->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $foreign->id])->assertNotFound();
        $this->assertSame($b->id, $session->fresh()->assigned_waiter_user_id);
        $this->assertSame(2, $this->assignmentCount());
    }

    public function test_deactivated_waiter_is_shown_pending_and_reconciled_once_on_entry(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $a = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        $b = $this->createStaff($org, $restaurant, 'waiter', 'W2');
        $table = $this->createTable($restaurant);
        $session = app(OpenTableAction::class)->execute($table, $a, 2);
        $this->onShift($restaurant, $b);
        app(UpdateStaffAction::class)->execute($org, $a, ['status' => 'inactive'], $owner);
        $snapshot = $this->as($owner)->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")->assertOk();
        $tables = $snapshot->json('data.unassigned_tables');
        $this->assertSame('pending', $tables[0]['session']['assignment_status']);
        $this->assertNull($tables[0]['session']['assigned_waiter']);
        $this->assertSame($a->id, $session->fresh()->assigned_waiter_user_id, 'GET must not mutate assignment');
        app(EnterPublicTableAction::class)->execute($table->public_token, null);
        app(EnterPublicTableAction::class)->execute($table->public_token, null);
        $this->assertSame($b->id, $session->fresh()->assigned_waiter_user_id);
        $this->assertSame(2, $this->assignmentCount());
    }

    public function test_removed_restaurant_membership_becomes_pending_without_blocking_entry(): void
    {
        [$org, , $restaurant] = $this->createTenant();
        $a = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        $table = $this->createTable($restaurant);
        $session = app(OpenTableAction::class)->execute($table, $a, 2);
        $a->restaurants()->detach($restaurant->id);
        $this->postJson("/api/v1/public/tables/{$table->public_token}/enter")->assertOk();
        $this->assertNull($session->fresh()->assigned_waiter_user_id);
        $this->assertSame(1, RestaurantActivityEvent::query()->where('type', Type::TABLE_ASSIGNMENT_PENDING)->count());
    }

    public function test_transfer_preserves_valid_waiter_and_notification_opens_current_table(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $a = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        $session = app(OpenTableAction::class)->execute($this->createTable($restaurant), $a, 2);
        $target = $this->createTable($restaurant);
        app(TransferTableSessionAction::class)->execute($session, $target, $owner);
        $this->assertSame($a->id, $session->fresh()->assigned_waiter_user_id);
        $this->as($a)->getJson("/api/v1/restaurants/{$restaurant->id}/activity?scope=assignments")
            ->assertOk()->assertJsonPath('data.activity.0.target_table_id', $target->id);
        $this->assertSame(1, $this->assignmentCount());
    }

    public function test_new_occupation_does_not_inherit_previous_waiter(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $a = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        $table = $this->createTable($restaurant);
        $session = app(OpenTableAction::class)->execute($table, $a, 2);
        app(VoidEmptyTableSessionAction::class)->execute($session, $owner, null);
        $new = app(EnterPublicTableAction::class)->execute($table->public_token, null)->activeSession;
        $this->assertNotSame($session->id, $new->id);
        $this->assertNull($new->assigned_waiter_user_id, 'No active shift: prior occupation must not supply a waiter');
    }

    public function test_manual_assignment_reloads_candidate_instead_of_trusting_a_stale_user(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = app(OpenTableAction::class)->execute($table, $owner, 2);
        $waiter = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        User::query()->whereKey($waiter->id)->update(['status' => User::STATUS_SUSPENDED]);
        $this->expectException(WaiterAssignmentIneligibleException::class);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
    }

    public function test_public_request_reconciles_a_waiter_who_lost_restaurant_membership(): void
    {
        [$org, , $restaurant] = $this->createTenant();
        $a = $this->createStaff($org, $restaurant, 'waiter', 'W1');
        $b = $this->createStaff($org, $restaurant, 'waiter', 'W2');
        $table = $this->createTable($restaurant);
        $session = app(OpenTableAction::class)->execute($table, $a, 2);
        $this->onShift($restaurant, $b);
        $a->restaurants()->detach($restaurant->id);
        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter", [], ['X-Table-Visit' => $session->feedback_token])->assertCreated();
        $this->assertSame($b->id, $session->fresh()->assigned_waiter_user_id);
        $this->assertSame(2, $this->assignmentCount());
        $notice = RestaurantActivityEvent::query()->where('type', Type::TABLE_WAITER_ASSIGNED)->latest('id')->firstOrFail();
        $this->assertSame('table_request', $notice->metadata['source']);
    }
}

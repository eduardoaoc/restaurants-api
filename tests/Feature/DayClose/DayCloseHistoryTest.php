<?php

namespace Tests\Feature\DayClose;

use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantActivityEvent;
use App\Models\RestaurantDayClose;
use App\Support\DayClose\DayCloseFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 9.1A — immutable snapshot, idempotency, history, detail,
 * annotations, audit/activity, permissions and tenant isolation.
 */
class DayCloseHistoryTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_snapshot_is_immutable_after_operational_changes(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '100.00');
        $this->at('2026-10-02 18:00:00');
        $session = $this->servedPaidSession($restaurant, $owner, '42.00', 'cash', productName: 'Paella');
        $this->at('2026-10-02 21:00:00');
        $created = $this->closeDay($restaurant, $owner, ['counted_cash' => '140.00', 'notes' => 'Terminal reiniciado a las 21:15'])->assertCreated()->json('data');
        $before = $this->as($owner)->getJson("/api/v1/day-closes/{$created['id']}")->assertOk()->json('data');

        // Operational data changes afterwards.
        $this->at('2026-10-03 10:00:00');
        $session->table->update(['name' => 'Renamed table']);
        $restaurant->update(['name' => 'Renamed restaurant']);
        $restaurant->settings()->update(['timezone' => 'Asia/Tokyo', 'cash_difference_note_threshold' => '0.01', 'preparation_delay_threshold_minutes' => 1]);
        $this->createFeedback($session, ['overall' => 1]); // late feedback for the closed day's session
        $this->servedPaidSession($restaurant, $owner, '9.00', 'card');

        $after = $this->as($owner)->getJson("/api/v1/day-closes/{$created['id']}")->assertOk()->json('data');
        $this->assertSame($before, $after);
        $this->assertSame('42.00', $after['total_received']);
        $this->assertSame('Europe/Madrid', $after['timezone']);

        // The persisted hash matches the persisted report's canonical JSON.
        $this->assertSame($after['report_sha256'], DayCloseFormat::hash($after['report']));
        $this->assertSame($after['report_sha256'], DayCloseFormat::hash(RestaurantDayClose::query()->findOrFail($created['id'])->report));

        // Annotations never touch report/hash/totals/has_incidents.
        $this->as($owner)->postJson("/api/v1/day-closes/{$created['id']}/annotations", ['body' => 'Cobro duplicado devuelto el 03/10.'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Cobro duplicado devuelto el 03/10.')
            ->assertJsonPath('data.created_by.id', $owner->id);
        $annotated = $this->as($owner)->getJson("/api/v1/day-closes/{$created['id']}")->assertOk()->json('data');
        $this->assertCount(1, $annotated['annotations']);
        unset($annotated['annotations'], $after['annotations']);
        $this->assertSame($after, $annotated);
        $this->assertDatabaseHas('audit_logs', ['event' => AuditLog::EVENT_DAY_CLOSE_ANNOTATION_ADDED, 'resource_id' => $created['id']]);

        // The model refuses any rewrite.
        $this->expectException(LogicException::class);
        RestaurantDayClose::query()->findOrFail($created['id'])->update(['total_received' => '1.00']);
    }

    public function test_audit_and_activity_on_close(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 18:00:00');
        $this->servedPaidSession($restaurant, $owner, '30.00');
        $this->at('2026-10-02 21:00:00');
        $data = $this->closeDay($restaurant, $owner, ['counted_cash' => '29.00'])->assertCreated()->json('data');

        $audit = AuditLog::query()->where('event', AuditLog::EVENT_DAY_CLOSE_COMPLETED)->sole();
        $this->assertSame($data['id'], $audit->resource_id);
        $this->assertSame('2026-10-02', $audit->metadata['business_date']);
        $this->assertSame('-1.00', $audit->metadata['cash_difference']);
        $this->assertSame($data['report_sha256'], $audit->metadata['report_sha256']);

        $event = RestaurantActivityEvent::query()->where('type', 'day_close.completed')->sole();
        $this->assertEquals([
            'day_close_public_id' => $data['public_id'],
            'business_date' => '2026-10-02',
            'total_received' => '30.00',
            'cash_difference' => '-1.00',
            'has_incidents' => true,
        ], $event->metadata);

        $this->as($owner)->getJson("/api/v1/restaurants/{$restaurant->id}/activity")->assertOk()
            ->assertJsonPath('data.activity.0.type', 'day_close.completed');
    }

    public function test_idempotent_replay_and_conflicting_reuse(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '10.00');
        $this->at('2026-10-02 21:00:00');
        $preview = $this->dayClosePreview($restaurant, $owner);
        $payload = ['period_started_at' => $preview['period']['period_started_at'], 'expected_cash_seen' => '10.00', 'counted_cash' => '10.00', 'notes' => 'ok'];
        $uri = "/api/v1/restaurants/{$restaurant->id}/day-closes";

        $first = $this->as($owner)->postJson($uri, $payload, ['Idempotency-Key' => 'close-1'])->assertCreated()->json('data');

        // Double-click / retry after a timeout: same close, 200.
        $this->at('2026-10-02 21:00:05');
        $replay = $this->as($owner)->postJson($uri, [...$payload, 'counted_cash' => '10.0'], ['Idempotency-Key' => 'close-1'])->assertOk()->json('data');
        $this->assertSame($first, $replay);

        $this->as($owner)->postJson($uri, [...$payload, 'counted_cash' => '11.00'], ['Idempotency-Key' => 'close-1'])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->as($owner)->postJson($uri, $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(1, RestaurantDayClose::query()->count());
    }

    public function test_history_filters_and_pagination(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $this->setDefaultOpeningFloat($restaurant, '0.00');

        $this->at('2026-10-02 21:00:00');
        $this->closeDay($restaurant, $owner)->assertCreated();
        $this->at('2026-10-03 21:00:00');
        $this->closeDay($restaurant, $manager, ['counted_cash' => '1.00'])->assertCreated();
        $this->at('2026-10-04 21:00:00');
        $this->closeDay($restaurant, $owner)->assertCreated();

        $uri = "/api/v1/restaurants/{$restaurant->id}/day-closes";
        $all = $this->as($manager)->getJson($uri)->assertOk();
        $this->assertSame(['2026-10-04', '2026-10-03', '2026-10-02'], array_column($all->json('data.day_closes'), 'business_date'));
        $this->assertArrayNotHasKey('report', $all->json('data.day_closes.0'));

        $this->assertSame(['2026-10-03'], array_column($this->as($manager)->getJson("{$uri}?has_incidents=1")->json('data.day_closes'), 'business_date'));
        $this->assertSame(['2026-10-04', '2026-10-02'], array_column($this->as($manager)->getJson("{$uri}?has_incidents=0")->json('data.day_closes'), 'business_date'));
        $this->assertSame(['2026-10-03'], array_column($this->as($manager)->getJson("{$uri}?closed_by={$manager->id}")->json('data.day_closes'), 'business_date'));
        $this->assertSame(['2026-10-03', '2026-10-02'], array_column($this->as($manager)->getJson("{$uri}?from=2026-10-01&to=2026-10-03")->json('data.day_closes'), 'business_date'));

        $page1 = $this->as($manager)->getJson("{$uri}?per_page=2")->assertOk();
        $this->assertCount(2, $page1->json('data.day_closes'));
        $page2 = $this->as($manager)->getJson("{$uri}?per_page=2&cursor=".$page1->json('meta.next_cursor'))->assertOk();
        $this->assertSame(['2026-10-02'], array_column($page2->json('data.day_closes'), 'business_date'));

        $this->as($manager)->getJson("{$uri}?from=2026-10-05&to=2026-10-01")->assertUnprocessable();
    }

    public function test_permission_matrix(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $staff = [
            'manager' => $this->createStaff($organization, $restaurant, 'manager', 'M-1'),
            'cashier' => $this->createStaff($organization, $restaurant, 'cashier', 'C-1'),
            'waiter' => $this->createStaff($organization, $restaurant, 'waiter', 'W-1'),
            'kitchen' => $this->createStaff($organization, $restaurant, 'kitchen', 'K-1'),
        ];
        $this->at('2026-10-02 21:00:00');
        $close = $this->closeDay($restaurant, $owner)->assertCreated()->json('data');

        $expect = [
            // role => [preview, cash movements, history, detail, annotate]
            'manager' => [200, 200, 200, 200, 201],
            'cashier' => [200, 200, 403, 403, 403],
            'waiter' => [200, 200, 403, 403, 403],
            'kitchen' => [403, 403, 403, 403, 403],
        ];

        foreach ($expect as $role => [$preview, $movements, $history, $detail, $annotate]) {
            $user = $staff[$role];
            $this->as($user)->getJson("/api/v1/restaurants/{$restaurant->id}/day-close/preview")->assertStatus($preview);
            $this->as($user)->getJson("/api/v1/restaurants/{$restaurant->id}/cash-movements")->assertStatus($movements);
            $this->as($user)->getJson("/api/v1/restaurants/{$restaurant->id}/day-closes")->assertStatus($history);
            $this->as($user)->getJson("/api/v1/day-closes/{$close['id']}")->assertStatus($detail);
            $this->as($user)->postJson("/api/v1/day-closes/{$close['id']}/annotations", ['body' => "Nota {$role}"])->assertStatus($annotate);
        }

        // Kitchen can neither record cash nor close.
        $this->as($staff['kitchen'])->postJson("/api/v1/restaurants/{$restaurant->id}/cash-movements", ['type' => 'pay_in', 'amount' => '1.00', 'reason' => 'x'], ['Idempotency-Key' => 'k'])->assertForbidden();
        $this->as($staff['kitchen'])->postJson("/api/v1/restaurants/{$restaurant->id}/day-closes", ['period_started_at' => $close['period_ended_at'], 'expected_cash_seen' => '0.00', 'counted_cash' => '0.00'], ['Idempotency-Key' => 'k'])->assertForbidden();
    }

    public function test_waiter_and_cashier_can_close_the_day(): void
    {
        foreach (['waiter', 'cashier'] as $i => $role) {
            [$organization, , $restaurant] = $this->createTenant();
            $this->setDefaultOpeningFloat($restaurant, '0.00');
            $user = $this->createStaff($organization, $restaurant, $role, 'S-'.$i);
            $this->at('2026-10-0'.($i + 2).' 21:00:00');

            $this->closeDay($restaurant, $user)->assertCreated()->assertJsonPath('data.closed_by.id', $user->id);
        }
    }

    public function test_tenant_isolation(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerB = $this->createStaff($organization, $restaurantB, 'manager', 'MB-1');
        [, $otherOwner] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurantA, '0.00');
        $this->at('2026-10-02 21:00:00');
        $close = $this->closeDay($restaurantA, $owner)->assertCreated()->json('data');

        // Sibling restaurant's manager (restaurant-scoped): 404 everywhere on A.
        $this->as($managerB)->getJson("/api/v1/day-closes/{$close['id']}")->assertNotFound();
        $this->as($managerB)->postJson("/api/v1/day-closes/{$close['id']}/annotations", ['body' => 'x'])->assertNotFound();
        $this->as($managerB)->getJson("/api/v1/restaurants/{$restaurantA->id}/day-closes")->assertNotFound();
        $this->as($managerB)->getJson("/api/v1/restaurants/{$restaurantA->id}/day-close/preview")->assertNotFound();
        $this->as($managerB)->getJson("/api/v1/restaurants/{$restaurantA->id}/cash-movements")->assertNotFound();

        // Another organization: 404.
        $this->as($otherOwner)->getJson("/api/v1/day-closes/{$close['id']}")->assertNotFound();
        $this->as($otherOwner)->getJson("/api/v1/restaurants/{$restaurantA->id}/day-closes")->assertNotFound();

        // B's own history is empty — A's close never leaks into it.
        $this->as($managerB)->getJson("/api/v1/restaurants/{$restaurantB->id}/day-closes")->assertOk()->assertJsonCount(0, 'data.day_closes');
    }

    public function test_capabilities_are_projected_in_auth_context(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        $waiterPermissions = $this->as($waiter)->getJson('/api/v1/auth/context')->json('data.organizations.0.restaurants.0.permissions');
        $this->assertContains('close_daily_operation', $waiterPermissions);
        $this->assertNotContains('view_daily_closes', $waiterPermissions);

        $managerPermissions = $this->as($manager)->getJson('/api/v1/auth/context')->json('data.organizations.0.restaurants.0.permissions');
        $this->assertContains('close_daily_operation', $managerPermissions);
        $this->assertContains('view_daily_closes', $managerPermissions);
    }

    public function test_notes_are_plain_text(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 21:00:00');

        $this->closeDay($restaurant, $owner, ['notes' => str_repeat('a', 2001)])->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->closeDay($restaurant, $owner, ['notes' => "  <b>Terminal</b>\u{0007} reiniciado\n  "])->assertCreated()
            ->assertJsonPath('data.notes', '<b>Terminal</b> reiniciado')
            ->assertJsonPath('data.has_incidents', true);
    }
}

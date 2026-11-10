<?php

namespace Tests\Feature\TableSession;

use App\Events\Realtime\TableSessionGuestCountChanged;
use App\Models\PaymentRecord;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class GuestCountUpdateTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function updateUrl(TableSession $session): string
    {
        return "/api/v1/table-sessions/{$session->id}/guest-count";
    }

    public function test_authorized_staff_updates_count_version_and_broadcasts(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $table->update(['capacity' => 4]);
        $session = $this->openSession($table, $owner, 2);
        Event::fake([TableSessionGuestCountChanged::class]);

        $this->actingAs($owner, 'web')->patchJson($this->updateUrl($session), [
            'guest_count' => 3,
            'expected_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.session.guest_count', 3)
            ->assertJsonPath('data.session.guest_count_version', 2);

        Event::assertDispatched(TableSessionGuestCountChanged::class, function ($event) use ($table, $session, $restaurant) {
            $payload = $event->broadcastWith();
            return $payload['restaurant_id'] === $restaurant->id
                && $payload['table_id'] === $table->id
                && $payload['table_session_id'] === $session->id
                && $payload['previous_guest_count'] === 2
                && $payload['guest_count'] === 3
                && isset($payload['event_id'], $payload['occurred_at']);
        });
    }

    public function test_kitchen_and_unauthenticated_users_cannot_update(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $session = $this->openSession($this->createTable($restaurant), $owner);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')->patchJson($this->updateUrl($session), ['guest_count' => 3, 'expected_version' => 1])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->patchJson($this->updateUrl($session), ['guest_count' => 3, 'expected_version' => 1])->assertUnauthorized();
    }

    public function test_cross_tenant_session_is_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [$organizationB, $ownerB, $restaurantB] = $this->createTenant();
        $sessionB = $this->openSession($this->createTable($restaurantB), $ownerB);
        $this->actingAs($ownerA, 'web')->patchJson($this->updateUrl($sessionB), ['guest_count' => 3, 'expected_version' => 1])->assertNotFound();
    }

    public function test_session_outside_restaurant_scope_is_not_found(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $restaurantOther = \App\Models\Restaurant::factory()->create(['organization_id' => $organization->id]);
        $sessionOther = $this->openSession($this->createTable($restaurantOther), $manager);

        $this->actingAs($manager, 'web')->patchJson($this->updateUrl($sessionOther), ['guest_count' => 3, 'expected_version' => 1])->assertNotFound();
    }

    public function test_invalid_counts_and_capacity_overflow_are_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $table->update(['capacity' => 3]);
        $session = $this->openSession($table, $owner, 2);

        foreach ([0, -1, 1.5, 101] as $invalid) {
            $this->actingAs($owner, 'web')->patchJson($this->updateUrl($session), ['guest_count' => $invalid, 'expected_version' => 1])
                ->assertUnprocessable()->assertJsonValidationErrors(['guest_count']);
        }
        $this->actingAs($owner, 'web')->patchJson($this->updateUrl($session), ['guest_count' => 4, 'expected_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['guest_count']);
        $this->assertSame(2, $session->fresh()->guest_count);
    }

    public function test_closed_session_and_stale_version_return_conflicts(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $session = $this->openSession($this->createTable($restaurant), $owner, 2);
        $this->actingAs($owner, 'web')->patchJson($this->updateUrl($session), ['guest_count' => 3, 'expected_version' => 1])->assertOk();
        $this->actingAs($owner, 'web')->patchJson($this->updateUrl($session), ['guest_count' => 4, 'expected_version' => 1])
            ->assertConflict()->assertJsonPath('error.code', 'GUEST_COUNT_VERSION_CONFLICT');

        $session->fresh()->update(['status' => 'closed']);
        $this->actingAs($owner, 'web')->patchJson($this->updateUrl($session), ['guest_count' => 4, 'expected_version' => 2])->assertConflict();
    }

    public function test_unchanged_count_is_a_no_op_without_broadcast(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $session = $this->openSession($this->createTable($restaurant), $owner, 2);
        Event::fake([TableSessionGuestCountChanged::class]);

        $this->actingAs($owner, 'web')->patchJson($this->updateUrl($session), ['guest_count' => 2, 'expected_version' => 1])
            ->assertOk()->assertJsonPath('data.session.guest_count_version', 1);

        Event::assertNotDispatched(TableSessionGuestCountChanged::class);
        $this->assertSame(2, $session->fresh()->guest_count);
    }

    public function test_count_update_does_not_change_orders_or_payments(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $session = $this->openSession($this->createTable($restaurant), $owner, 2);
        $orderCount = $session->orders()->count();
        $paymentCount = PaymentRecord::query()->where('table_session_id', $session->id)->count();

        $this->actingAs($owner, 'web')->patchJson($this->updateUrl($session), ['guest_count' => 4, 'expected_version' => 1])->assertOk();

        $this->assertSame($orderCount, $session->orders()->count());
        $this->assertSame($paymentCount, PaymentRecord::query()->where('table_session_id', $session->id)->count());
        $this->assertSame('occupied', $session->fresh()->status);
    }
}

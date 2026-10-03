<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantDayClose;
use App\Models\RestaurantDayCloseDelivery;
use App\Models\RestaurantReportRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWhatsApp;
use Tests\TestCase;

/**
 * CARTA 9.1E — deliveries list + manual resend.
 */
class DayCloseWhatsAppResendTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, InteractsWithWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->configureWhatsApp();
        Http::fake([self::WA_MESSAGES_URL => Http::sequence()
            ->push($this->metaAccepted('wamid.AUTO'), 200)
            ->push($this->metaAccepted('wamid.R1'), 200)
            ->push($this->metaAccepted('wamid.R2'), 200)
            ->push($this->metaAccepted('wamid.R3'), 200)]);
    }

    /**
     * @return array{0: Restaurant, 1: User, 2: RestaurantDayClose}
     */
    private function closedWithWhatsApp(): array
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->enableWhatsApp($restaurant, $owner);
        $this->at('2026-10-02 21:00:00');
        $id = $this->closeDay($restaurant, $owner)->assertCreated()->json('data.id');

        return [$restaurant, $owner, RestaurantDayClose::query()->findOrFail($id)];
    }

    private function resend(RestaurantDayClose $dayClose, User $user, ?string $key = 'resend-1'): TestResponse
    {
        return $this->as($user)->postJson("/api/v1/day-closes/{$dayClose->id}/deliveries", [], $key === null ? [] : ['Idempotency-Key' => $key]);
    }

    public function test_owner_and_manager_resend_creates_a_new_manual_delivery(): void
    {
        [$restaurant, $owner, $dayClose] = $this->closedWithWhatsApp();
        $manager = $this->createStaff($restaurant->organization, $restaurant, 'manager', 'M-1');

        $this->resend($dayClose, $owner, 'k-owner')->assertCreated()
            ->assertJsonPath('data.kind', 'manual_resend')
            ->assertJsonPath('data.requested_by.id', $owner->id)
            ->assertJsonPath('data.recipient.phone_masked', '+34 ••• •• 56 78');
        $this->resend($dayClose, $manager, 'k-manager')->assertCreated();

        $this->assertSame(3, RestaurantDayCloseDelivery::query()->count()); // automatic + 2 resends
        $this->assertSame(1, RestaurantDayCloseDelivery::query()->where('kind', 'automatic')->count());
        $this->assertSame(1, RestaurantDayClose::query()->count()); // never a new close
        $this->assertSame(2, AuditLog::query()->where('event', AuditLog::EVENT_DAY_CLOSE_WHATSAPP_RESEND_REQUESTED)->count());
        Http::assertSentCount(3);

        $list = $this->as($owner)->getJson("/api/v1/day-closes/{$dayClose->id}/deliveries")->assertOk();
        $this->assertSame(['automatic', 'manual_resend', 'manual_resend'], array_column($list->json('data.deliveries'), 'kind'));
        $this->assertSame(['accepted', 'accepted', 'accepted'], array_column($list->json('data.deliveries'), 'status'));
        $this->assertStringNotContainsString('612345678', $list->getContent());
        $this->assertStringNotContainsString('wamid', $list->getContent());
    }

    public function test_idempotency_key_replays_and_conflicts(): void
    {
        [$restaurant, $owner, $dayClose] = $this->closedWithWhatsApp();
        $manager = $this->createStaff($restaurant->organization, $restaurant, 'manager', 'M-1');

        $first = $this->resend($dayClose, $owner, 'same')->assertCreated()->json('data.id');
        $this->assertSame($first, $this->resend($dayClose, $owner, 'same')->assertOk()->json('data.id'));
        $this->resend($dayClose, $manager, 'same')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->resend($dayClose, $owner, null)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(2, RestaurantDayCloseDelivery::query()->count());
        Http::assertSentCount(2);
    }

    public function test_a_pending_delivery_blocks_another_resend(): void
    {
        [, $owner, $dayClose] = $this->closedWithWhatsApp();
        Queue::fake();

        $this->resend($dayClose, $owner, 'a')->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->resend($dayClose, $owner, 'b')->assertStatus(409)->assertJsonPath('error.code', 'DELIVERY_ALREADY_PENDING');
    }

    public function test_guards(): void
    {
        [$restaurant, $owner, $dayClose] = $this->closedWithWhatsApp();

        config(['whatsapp.enabled' => false]);
        $this->resend($dayClose, $owner, 'g1')->assertUnprocessable()->assertJsonPath('error.code', 'WHATSAPP_NOT_AVAILABLE');
        config(['whatsapp.enabled' => true]);

        RestaurantReportRecipient::query()->update(['consent_revoked_at' => now()]);
        $this->resend($dayClose, $owner, 'g2')->assertUnprocessable()->assertJsonPath('error.code', 'CONSENT_REQUIRED');

        RestaurantReportRecipient::query()->update(['active' => false]);
        $this->resend($dayClose, $owner, 'g3')->assertUnprocessable()->assertJsonPath('error.code', 'RECIPIENT_REQUIRED');

        $restaurant->settings()->update(['daily_close_whatsapp_enabled' => false]);
        $this->travel(61)->seconds(); // past the 3/minute resend throttle
        $this->resend($dayClose, $owner, 'g4')->assertUnprocessable()->assertJsonPath('error.code', 'WHATSAPP_DISABLED');

        $this->assertSame(1, RestaurantDayCloseDelivery::query()->count());
    }

    public function test_resend_goes_to_the_current_recipient_with_its_own_snapshot(): void
    {
        [$restaurant, $owner, $dayClose] = $this->closedWithWhatsApp();
        $this->putWhatsAppSettings($restaurant, $owner, true, ['name' => 'Nuevo', 'phone' => '+34699000111', 'consent_confirmed' => true])->assertOk();

        $this->resend($dayClose, $owner)->assertCreated()->assertJsonPath('data.recipient.name', 'Nuevo')->assertJsonPath('data.recipient.phone_masked', '+34 ••• •• 01 11');

        $automatic = RestaurantDayCloseDelivery::query()->where('kind', 'automatic')->sole();
        $this->assertSame('+34612345678', $automatic->recipient_phone_e164); // history untouched
        $this->assertSame('+34699000111', Http::recorded()[1][0]->data()['to']);
    }

    public function test_roles_without_view_daily_closes_get_403(): void
    {
        [$restaurant, , $dayClose] = $this->closedWithWhatsApp();

        foreach (['waiter', 'cashier', 'kitchen'] as $i => $role) {
            $user = $this->createStaff($restaurant->organization, $restaurant, $role, 'S-'.$i);
            $this->resend($dayClose, $user, "k{$i}")->assertForbidden();
            $this->as($user)->getJson("/api/v1/day-closes/{$dayClose->id}/deliveries")->assertForbidden();
        }

        $this->assertSame(1, RestaurantDayCloseDelivery::query()->count());
    }

    public function test_tenant_isolation(): void
    {
        [$restaurant, , $dayClose] = $this->closedWithWhatsApp();
        $sibling = Restaurant::factory()->create(['organization_id' => $restaurant->organization_id]);
        $siblingManager = $this->createStaff($restaurant->organization, $sibling, 'manager', 'MB-1');
        [, $otherOwner] = $this->createTenant();

        foreach ([$siblingManager, $otherOwner] as $user) {
            $this->as($user)->getJson("/api/v1/day-closes/{$dayClose->id}/deliveries")->assertNotFound();
            $this->resend($dayClose, $user, 'x'.$user->id)->assertNotFound();
            $this->as($user)->getJson("/api/v1/restaurants/{$restaurant->id}/day-close-whatsapp-settings")->assertNotFound();
            $this->putWhatsAppSettings($restaurant, $user, false, null)->assertNotFound();
        }

        $this->assertTrue(RestaurantReportRecipient::query()->sole()->active);
    }

    public function test_resend_is_throttled(): void
    {
        [, $owner, $dayClose] = $this->closedWithWhatsApp();
        Queue::fake(); // keep deliveries pending: each call is a guarded attempt

        $this->resend($dayClose, $owner, 't1')->assertCreated();
        $this->resend($dayClose, $owner, 't2')->assertStatus(409);
        $this->resend($dayClose, $owner, 't3')->assertStatus(409);
        $this->resend($dayClose, $owner, 't4')->assertStatus(429);
    }
}

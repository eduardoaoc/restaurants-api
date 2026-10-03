<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantReportRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWhatsApp;
use Tests\TestCase;

/**
 * CARTA 9.1E — restaurant WhatsApp configuration and recipient.
 */
class WhatsAppSettingsTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, InteractsWithWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->configureWhatsApp();
    }

    public function test_defaults_disabled_without_recipient(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->as($owner)->getJson("/api/v1/restaurants/{$restaurant->id}/day-close-whatsapp-settings")->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.recipient', null)
            ->assertJsonPath('data.consent_text', 'Confirmo que esta persona ha aceptado recibir los cierres diarios por WhatsApp.');
    }

    public function test_create_recipient_with_consent_and_enable(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $response = $this->putWhatsAppSettings($restaurant, $owner, true, ['name' => 'Carlos Ruiz', 'phone' => '+34612345678', 'consent_confirmed' => true, 'user_id' => $owner->id])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.recipient.name', 'Carlos Ruiz')
            ->assertJsonPath('data.recipient.phone_masked', '+34 ••• •• 56 78')
            ->assertJsonPath('data.recipient.linked_user_id', $owner->id)
            ->assertJsonPath('data.recipient.consent_method', 'declared_by_admin')
            ->assertJsonPath('data.recipient.consent_text_version', 'v1');

        $this->assertStringNotContainsString('612345678', $response->getContent());
        $this->assertStringNotContainsString('612345678', $this->as($owner)->getJson("/api/v1/restaurants/{$restaurant->id}/day-close-whatsapp-settings")->getContent());

        // Encrypted at rest, keyed hash, never the clear number in the row.
        $row = DB::table('restaurant_report_recipients')->first();
        $this->assertStringNotContainsString('612345678', $row->phone_e164);
        $this->assertSame('+34612345678', RestaurantReportRecipient::query()->first()->phone_e164);
        $this->assertNotSame(hash('sha256', '+34612345678'), $row->phone_hash);
        $this->assertSame(64, strlen($row->phone_hash));
        $this->assertTrue((bool) $restaurant->settings()->first()->daily_close_whatsapp_enabled);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPhones(): array
    {
        return [
            'no plus' => ['34612345678'],
            'spaces' => ['+34 612 345 678'],
            'dashes' => ['+34-612-345-678'],
            'leading zero country' => ['+0612345678'],
            'too short' => ['+3461234'],
            'too long' => ['+3461234567890123'],
            'letters' => ['+34abc345678'],
            'local' => ['612345678'],
        ];
    }

    #[DataProvider('invalidPhones')]
    public function test_only_strict_e164_is_accepted(string $phone): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->putWhatsAppSettings($restaurant, $owner, false, ['name' => 'X', 'phone' => $phone, 'consent_confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient.phone');
        $this->assertSame(0, RestaurantReportRecipient::query()->count());
    }

    public function test_consent_is_required_for_a_new_number(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->putWhatsAppSettings($restaurant, $owner, false, ['name' => 'Carlos', 'phone' => '+34612345678'])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient.consent_confirmed');
        $this->putWhatsAppSettings($restaurant, $owner, false, ['name' => 'Carlos', 'phone' => '+34612345678', 'consent_confirmed' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient.consent_confirmed');
    }

    public function test_enabling_requires_a_usable_recipient(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->putWhatsAppSettings($restaurant, $owner, true, null)->assertUnprocessable()->assertJsonValidationErrors('enabled');
        $this->assertFalse((bool) $restaurant->settings()->first()->daily_close_whatsapp_enabled);
    }

    public function test_changing_the_number_needs_new_consent_and_creates_a_new_recipient(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->enableWhatsApp($restaurant, $owner, '+34612345678');
        $old = RestaurantReportRecipient::query()->sole();

        // Same number, new name: updated in place, consent kept.
        $this->putWhatsAppSettings($restaurant, $owner, true, ['name' => 'Carlos R.'])->assertOk()
            ->assertJsonPath('data.recipient.id', $old->id)
            ->assertJsonPath('data.recipient.name', 'Carlos R.');

        // New number without consent: refused, nothing changes.
        $this->putWhatsAppSettings($restaurant, $owner, true, ['name' => 'Carlos R.', 'phone' => '+34699000111'])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient.consent_confirmed');
        $this->assertTrue($old->refresh()->active);

        // New number with consent: new row, old one deactivated + revoked.
        $this->putWhatsAppSettings($restaurant, $owner, true, ['name' => 'Carlos R.', 'phone' => '+34699000111', 'consent_confirmed' => true])->assertOk()
            ->assertJsonPath('data.recipient.phone_masked', '+34 ••• •• 01 11');
        $old->refresh();
        $this->assertFalse($old->active);
        $this->assertNotNull($old->consent_revoked_at);
        $new = RestaurantReportRecipient::query()->where('active', true)->sole();
        $this->assertNotSame($old->id, $new->id);
        $this->assertNotNull($new->consent_given_at);
    }

    public function test_disable_recipient_and_switch(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->enableWhatsApp($restaurant, $owner);

        $this->putWhatsAppSettings($restaurant, $owner, false, ['name' => 'Carlos Ruiz'])->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.status', 'disabled');
        $this->putWhatsAppSettings($restaurant, $owner, false, null)->assertOk()->assertJsonPath('data.recipient', null);

        $recipient = RestaurantReportRecipient::query()->sole();
        $this->assertFalse($recipient->active);
        $this->assertNotNull($recipient->consent_revoked_at);
    }

    public function test_audit_never_contains_the_full_phone(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->enableWhatsApp($restaurant, $owner, '+34612345678');
        $this->putWhatsAppSettings($restaurant, $owner, true, ['name' => 'Otro', 'phone' => '+34699000111', 'consent_confirmed' => true])->assertOk();
        $this->putWhatsAppSettings($restaurant, $owner, false, null)->assertOk();

        $events = AuditLog::query()->orderBy('id')->pluck('event')->all();
        foreach ([
            AuditLog::EVENT_WHATSAPP_RECIPIENT_CREATED,
            AuditLog::EVENT_WHATSAPP_CONSENT_RECORDED,
            AuditLog::EVENT_WHATSAPP_RECIPIENT_DISABLED,
            AuditLog::EVENT_WHATSAPP_CONSENT_REVOKED,
            AuditLog::EVENT_RESTAURANT_SETTINGS_UPDATED,
        ] as $event) {
            $this->assertContains($event, $events);
        }

        $dump = json_encode(AuditLog::query()->get(['changes', 'metadata'])->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('612345678', $dump);
        $this->assertStringNotContainsString('699000111', $dump);
        $this->assertStringContainsString('+34 ••• •• 56 78', $dump);
        $this->assertEquals(['old' => false, 'new' => true], AuditLog::query()->where('event', AuditLog::EVENT_RESTAURANT_SETTINGS_UPDATED)->first()->changes['daily_close_whatsapp_enabled']);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $sibling = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $siblingManager = $this->createStaff($organization, $sibling, 'manager', 'MB-1');
        [, $otherOwner] = $this->createTenant();
        $uri = "/api/v1/restaurants/{$restaurant->id}/day-close-whatsapp-settings";

        foreach (['waiter', 'cashier', 'kitchen'] as $i => $role) {
            $user = $this->createStaff($organization, $restaurant, $role, 'S-'.$i);
            $this->as($user)->getJson($uri)->assertForbidden();
            $this->putWhatsAppSettings($restaurant, $user, false, null)->assertForbidden();
        }

        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $this->as($manager)->getJson($uri)->assertOk();

        $this->as($siblingManager)->getJson($uri)->assertNotFound();
        $this->putWhatsAppSettings($restaurant, $siblingManager, false, null)->assertNotFound();
        $this->as($otherOwner)->getJson($uri)->assertNotFound();

        // A recipient user must belong to the organization.
        $this->putWhatsAppSettings($restaurant, $owner, false, ['name' => 'X', 'phone' => '+34612345678', 'consent_confirmed' => true, 'user_id' => $otherOwner->id])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient.user_id');
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\RestaurantActivityEvent;
use App\Models\RestaurantDayClose;
use App\Models\RestaurantDayCloseDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWhatsApp;
use Tests\TestCase;

/**
 * CARTA 9.1E — Meta webhook: verification handshake, X-Hub-Signature-256,
 * status progression.
 */
class WhatsAppWebhookTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, InteractsWithWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->configureWhatsApp();
    }

    private function acceptedDelivery(string $messageId = 'wamid.STATUS1'): RestaurantDayCloseDelivery
    {
        Http::fake([self::WA_MESSAGES_URL => Http::response($this->metaAccepted($messageId), 200)]);
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->enableWhatsApp($restaurant, $owner);
        $this->at('2026-10-02 21:00:00');
        $this->closeDay($restaurant, $owner)->assertCreated();
        $this->withoutHeader('Origin');

        $delivery = RestaurantDayCloseDelivery::query()->sole();
        $this->assertSame('accepted', $delivery->status);

        return $delivery;
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function metaStatus(string $messageId, string $status, int $timestamp, array $errors = []): array
    {
        return array_filter([
            'id' => $messageId,
            'status' => $status,
            'timestamp' => (string) $timestamp,
            'recipient_id' => '34612345678',
            'errors' => $errors ?: null,
        ]);
    }

    // --- GET verification -------------------------------------------------

    public function test_verification_returns_the_challenge_only_with_the_right_token(): void
    {
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token='.self::WA_VERIFY_TOKEN.'&hub.challenge=1158201444')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('1158201444', false);

        $wrong = $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=1158201444')->assertForbidden();
        $this->assertStringNotContainsString('1158201444', $wrong->getContent());
        $this->assertStringNotContainsString(self::WA_VERIFY_TOKEN, $wrong->getContent());

        $this->get('/api/v1/webhooks/whatsapp?hub.mode=unsubscribe&hub.verify_token='.self::WA_VERIFY_TOKEN.'&hub.challenge=1')->assertForbidden();
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=1')->assertForbidden();

        config(['whatsapp.webhook_verify_token' => null]);
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=1')->assertForbidden();
    }

    // --- POST signature ---------------------------------------------------

    public function test_signature_is_required_and_checked_over_the_raw_body(): void
    {
        $delivery = $this->acceptedDelivery();
        $payload = json_encode($this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'sent', 1790974800)]));

        $this->postSignedWebhook($payload, secret: null)->assertForbidden(); // missing
        $this->postSignedWebhook($payload, signatureOverride: 'sha256=deadbeef')->assertForbidden(); // wrong
        $this->postSignedWebhook($payload, secret: 'another-secret')->assertForbidden(); // other app
        $this->postSignedWebhook($payload, signatureOverride: hash_hmac('sha256', $payload, self::WA_APP_SECRET))->assertForbidden(); // no "sha256=" prefix

        // Body modified after signing (old signature).
        $tampered = str_replace('"sent"', '"read"', $payload);
        $this->postSignedWebhook($tampered, signatureOverride: 'sha256='.hash_hmac('sha256', $payload, self::WA_APP_SECRET))->assertForbidden();
        $this->assertSame('accepted', $delivery->refresh()->status);

        // Unicode-escaped raw bytes are signed as received, never re-encoded.
        $this->postSignedWebhook($payload)->assertOk()->assertJsonPath('received', true);
        $this->assertSame('sent', $delivery->refresh()->status);

        config(['whatsapp.app_secret' => null]);
        $this->postSignedWebhook($payload)->assertForbidden();
    }

    // --- Status progression -------------------------------------------------

    public function test_forward_progression_with_provider_timestamps(): void
    {
        $delivery = $this->acceptedDelivery();

        $this->postSignedWebhook($this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'sent', 1790974800)]))->assertOk();
        $this->assertSame('sent', $delivery->refresh()->status);
        $this->assertSame('2026-10-02 21:00:00', $delivery->sent_at->utc()->format('Y-m-d H:i:s'));

        $this->postSignedWebhook($this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'delivered', 1790974805)]))->assertOk();
        $this->assertSame('delivered', $delivery->refresh()->status);

        $this->postSignedWebhook($this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'read', 1790974860)]))->assertOk();
        $delivery->refresh();
        $this->assertSame('read', $delivery->status);
        $this->assertSame('2026-10-02 21:01:00', $delivery->read_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_out_of_order_never_regresses_and_fills_missing_timestamps(): void
    {
        $delivery = $this->acceptedDelivery();

        $this->postSignedWebhook($this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'read', 1790974860)]))->assertOk();
        $this->postSignedWebhook($this->statusWebhook([
            $this->metaStatus('wamid.STATUS1', 'delivered', 1790974805),
            $this->metaStatus('wamid.STATUS1', 'sent', 1790974800),
        ]))->assertOk();

        $delivery->refresh();
        $this->assertSame('read', $delivery->status);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertNotNull($delivery->sent_at);

        // A failure after it was read never wins.
        $this->postSignedWebhook($this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'failed', 1790972500, [['code' => 131026, 'title' => 'Message undeliverable']])]))->assertOk();
        $this->assertSame('read', $delivery->refresh()->status);
        $this->assertNull($delivery->failure_code);
    }

    public function test_duplicates_are_idempotent_and_create_no_noise(): void
    {
        $delivery = $this->acceptedDelivery();
        Queue::fake();
        $auditBefore = AuditLog::query()->count();
        $activityBefore = RestaurantActivityEvent::query()->count();
        $closeBefore = RestaurantDayClose::query()->sole()->toArray();
        $event = $this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'delivered', 1790974805)]);

        $this->postSignedWebhook($event)->assertOk();
        $first = $delivery->refresh()->delivered_at;
        $this->postSignedWebhook($event)->assertOk();
        $this->postSignedWebhook($event)->assertOk();

        $delivery->refresh();
        $this->assertSame('delivered', $delivery->status);
        $this->assertEquals($first, $delivery->delivered_at);
        $this->assertSame($auditBefore, AuditLog::query()->count());
        $this->assertSame($activityBefore, RestaurantActivityEvent::query()->count());
        $this->assertSame($closeBefore, RestaurantDayClose::query()->sole()->toArray());
        Queue::assertNothingPushed();
    }

    public function test_failed_status_is_stored_safely(): void
    {
        $delivery = $this->acceptedDelivery();

        $this->postSignedWebhook($this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'failed', 1790974810, [[
            'code' => 131026,
            'title' => 'Message undeliverable',
            'message' => 'Message undeliverable',
            'error_data' => ['details' => 'Recipient +34612345678 cannot receive this message'],
        ]])]))->assertOk();

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('131026', $delivery->failure_code);
        $this->assertSame('Recipient [redacted] cannot receive this message', $delivery->failure_reason);
        $this->assertSame('2026-10-02 21:00:10', $delivery->failed_at->utc()->format('Y-m-d H:i:s'));

        // Terminal: a later "delivered" does not resurrect it.
        $this->postSignedWebhook($this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'delivered', 1790974820)]))->assertOk();
        $this->assertSame('failed', $delivery->refresh()->status);
    }

    public function test_unknown_message_and_foreign_accounts_are_acknowledged_without_mutation(): void
    {
        $delivery = $this->acceptedDelivery();

        $this->postSignedWebhook($this->statusWebhook([$this->metaStatus('wamid.UNKNOWN', 'read', 1790974860)]))->assertOk();
        // Another phone number id / WABA (other provider account): ignored.
        $this->postSignedWebhook($this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'read', 1790974860)], phoneNumberId: '999999999'))->assertOk();
        $this->postSignedWebhook($this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'read', 1790974860)], wabaId: '888888888'))->assertOk();
        // Other objects/fields and garbage: acknowledged.
        $this->postSignedWebhook(['object' => 'page', 'entry' => []])->assertOk();
        $this->postSignedWebhook('not json at all')->assertOk();
        // Played / unknown statuses: ignored.
        $this->postSignedWebhook($this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'played', 1790974860)]))->assertOk();

        $this->assertSame('accepted', $delivery->refresh()->status);
    }

    public function test_webhook_needs_no_session_and_ignores_csrf(): void
    {
        $this->acceptedDelivery();
        Auth::forgetGuards();
        $this->flushSession();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postSignedWebhook($this->statusWebhook([$this->metaStatus('wamid.STATUS1', 'sent', 1790974800)]))
            ->assertOk();
    }
}

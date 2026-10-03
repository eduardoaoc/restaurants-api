<?php

namespace Tests\Concerns;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * WhatsApp Cloud API test helpers (CARTA 9.1E). Fake, isolated config —
 * the suite never calls the real Graph API (Http::fake everywhere).
 */
trait InteractsWithWhatsApp
{
    protected const WA_TOKEN = 'test-access-token-DO-NOT-LEAK-7f3a';

    protected const WA_APP_SECRET = 'test-app-secret-9b1c';

    protected const WA_VERIFY_TOKEN = 'test-verify-token-55aa';

    protected const WA_PHONE_NUMBER_ID = '106540352242922';

    protected const WA_WABA_ID = '102290129340398';

    protected const WA_MESSAGES_URL = 'https://graph.facebook.com/v25.0/106540352242922/messages';

    protected function configureWhatsApp(array $overrides = []): void
    {
        config(array_merge([
            'whatsapp.enabled' => true,
            'whatsapp.graph_api_version' => 'v25.0',
            'whatsapp.phone_number_id' => self::WA_PHONE_NUMBER_ID,
            'whatsapp.access_token' => self::WA_TOKEN,
            'whatsapp.waba_id' => self::WA_WABA_ID,
            'whatsapp.template_name' => 'aforo_cierre_diario',
            'whatsapp.template_language' => 'es_ES',
            'whatsapp.webhook_verify_token' => self::WA_VERIFY_TOKEN,
            'whatsapp.app_secret' => self::WA_APP_SECRET,
            'whatsapp.web_url' => 'https://app.aforo.test',
            'whatsapp.report_path' => '/app/day-close/{id}',
        ], $this->prefixWhatsApp($overrides)));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function prefixWhatsApp(array $overrides): array
    {
        $prefixed = [];
        foreach ($overrides as $key => $value) {
            $prefixed[str_starts_with($key, 'whatsapp.') ? $key : 'whatsapp.'.$key] = $value;
        }

        return $prefixed;
    }

    /**
     * @param  array<string, mixed>|null  $recipient
     */
    protected function putWhatsAppSettings(Restaurant $restaurant, User $user, bool $enabled, ?array $recipient): TestResponse
    {
        return $this->as($user)->putJson("/api/v1/restaurants/{$restaurant->id}/day-close-whatsapp-settings", [
            'enabled' => $enabled,
            'recipient' => $recipient,
        ]);
    }

    protected function enableWhatsApp(Restaurant $restaurant, User $owner, string $phone = '+34612345678', string $name = 'Carlos Ruiz'): void
    {
        $this->putWhatsAppSettings($restaurant, $owner, true, ['name' => $name, 'phone' => $phone, 'consent_confirmed' => true])->assertOk();
    }

    /**
     * POST a Meta-signed webhook payload (raw body, X-Hub-Signature-256).
     *
     * @param  array<string, mixed>|string  $payload
     */
    protected function postSignedWebhook(array|string $payload, ?string $secret = self::WA_APP_SECRET, ?string $signatureOverride = null): TestResponse
    {
        $raw = is_string($payload) ? $payload : json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($signatureOverride !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = $signatureOverride;
        } elseif ($secret !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
        }

        return $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], $headers, $raw);
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<string, mixed>
     */
    protected function statusWebhook(array $statuses, string $phoneNumberId = self::WA_PHONE_NUMBER_ID, string $wabaId = self::WA_WABA_ID): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $wabaId,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => $phoneNumberId],
                        'statuses' => $statuses,
                    ],
                ]],
            ]],
        ];
    }

    protected function metaAccepted(string $messageId = 'wamid.HBgLMzQ2MTIzNDU2NzgVAgARGBI'): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '+34612345678', 'wa_id' => '34612345678']],
            'messages' => [['id' => $messageId, 'message_status' => 'accepted']],
        ];
    }
}

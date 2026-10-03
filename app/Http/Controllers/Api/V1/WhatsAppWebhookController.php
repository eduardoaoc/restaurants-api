<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\WhatsApp\DeliveryStatusUpdater;
use App\Support\WhatsApp\WhatsAppConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * Meta WhatsApp Cloud API webhook (CARTA 9.1E). Public (no Sanctum, no
 * tenant) — Meta calls it — but authenticated:
 *
 *   GET  verification handshake: hub.mode=subscribe + hub.verify_token
 *        compared in constant time with WHATSAPP_WEBHOOK_VERIFY_TOKEN;
 *        answers hub.challenge as text/plain, 403 otherwise.
 *   POST status callbacks: X-Hub-Signature-256 ("sha256=" + HMAC-SHA256
 *        of the RAW body with META_APP_SECRET) is verified before the JSON
 *        is even parsed — 403 on missing/invalid signature. Only changes of
 *        field "messages" whose metadata.phone_number_id (and entry id,
 *        when WHATSAPP_WABA_ID is set) match AFORO's own sender are
 *        processed; anything else is ignored. Unknown message ids are
 *        acknowledged (200) without mutation — Meta would otherwise retry
 *        for days. Incoming user messages are NOT processed (no chatbot).
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppConfig $config,
        private readonly DeliveryStatusUpdater $statusUpdater,
    ) {}

    #[OA\Get(
        path: '/api/v1/webhooks/whatsapp',
        operationId: 'whatsAppWebhookVerify',
        summary: 'Meta webhook verification handshake (called by Meta, not by clients)',
        tags: ['Webhooks'],
        parameters: [
            new OA\Parameter(name: 'hub.mode', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: 'subscribe')),
            new OA\Parameter(name: 'hub.verify_token', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'hub.challenge', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The hub.challenge, as text/plain', content: new OA\MediaType(mediaType: 'text/plain', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: 403, description: 'Invalid mode or verify token'),
        ]
    )]
    public function verify(Request $request): Response
    {
        // PHP turns "hub.mode" into "hub_mode" in $_GET.
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));
        $expected = $this->config->verifyToken();

        if ($mode !== 'subscribe' || $expected === null || $token === '' || ! hash_equals($expected, $token)) {
            return response('Forbidden', 403, ['Content-Type' => 'text/plain']);
        }

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    #[OA\Post(
        path: '/api/v1/webhooks/whatsapp',
        operationId: 'whatsAppWebhookReceive',
        summary: 'Meta webhook callback: message status updates (called by Meta, not by clients)',
        description: 'Requires a valid X-Hub-Signature-256 over the raw body. Only status updates (sent, delivered, read, failed) of messages sent by AFORO\'s own phone number are applied; other events are acknowledged and ignored.',
        tags: ['Webhooks'],
        parameters: [new OA\Parameter(name: 'X-Hub-Signature-256', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: 'sha256=…'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Acknowledged'),
            new OA\Response(response: 403, description: 'Missing or invalid signature'),
        ]
    )]
    public function receive(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $secret = $this->config->appSecret();

        if ($secret === null || ! str_starts_with($signature, 'sha256=') || ! hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature)) {
            Log::warning('whatsapp.webhook.invalid_signature');

            return response()->json(['error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'Invalid signature.']], 403);
        }

        $payload = json_decode($raw, true);
        $applied = 0;
        $unknown = 0;

        if (is_array($payload) && ($payload['object'] ?? null) === 'whatsapp_business_account') {
            foreach ($payload['entry'] ?? [] as $entry) {
                if ($this->config->wabaId() !== null && (string) ($entry['id'] ?? '') !== $this->config->wabaId()) {
                    continue;
                }

                foreach ($entry['changes'] ?? [] as $change) {
                    $value = $change['value'] ?? [];

                    if (($change['field'] ?? null) !== 'messages' || (string) ($value['metadata']['phone_number_id'] ?? '') !== $this->config->phoneNumberId()) {
                        continue;
                    }

                    foreach ($value['statuses'] ?? [] as $status) {
                        if (! is_string($status['id'] ?? null) || ! is_string($status['status'] ?? null)) {
                            continue;
                        }

                        $matched = $this->statusUpdater->apply($status['id'], $status['status'], isset($status['timestamp']) ? (string) $status['timestamp'] : null, is_array($status['errors'] ?? null) ? $status['errors'] : []);
                        $matched ? $applied++ : $unknown++;
                    }
                }
            }
        }

        if ($unknown > 0) {
            Log::info('whatsapp.webhook.unknown_message_ids', ['count' => $unknown]);
        }

        return response()->json(['received' => true]);
    }
}

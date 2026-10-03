<?php

namespace App\Support\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * WhatsApp Cloud API client (CARTA 9.1E):
 * POST https://graph.facebook.com/{version}/{phone-number-id}/messages,
 * Authorization: Bearer <system user token>, type=template.
 *
 * Never throws for provider outcomes — always returns a classified
 * WhatsAppSendResult. Never logs the token, the phone number or the
 * template parameters; never uses ->throw() (whose exception message
 * embeds the response body). Logs carry only codes.
 */
final class MetaWhatsAppCloudApi implements WhatsAppProvider
{
    /**
     * Meta error codes documented as transient / safe to retry later
     * (support/error-codes): API & WABA rate limits, throughput, unknown
     * send error, service unavailable, recipient pair rate limit.
     */
    private const RETRYABLE_CODES = ['4', '80007', '130429', '131000', '131016', '131056'];

    public function __construct(private readonly WhatsAppConfig $config) {}

    public function sendTemplate(WhatsAppTemplateMessage $message): WhatsAppSendResult
    {
        if (! $this->config->readyToSend()) {
            throw new LogicException('WhatsApp Cloud API is not configured: '.implode(', ', $this->config->missingForSending()));
        }

        try {
            $response = Http::withToken($this->config->accessToken())
                ->acceptJson()
                ->asJson()
                ->timeout($this->config->timeout())
                ->connectTimeout($this->config->connectTimeout())
                ->post($this->config->messagesUrl(), $message->toPayload());
        } catch (ConnectionException $e) {
            // cURL 6/7: DNS/connect failure — the request never reached
            // Meta, so a retry cannot duplicate. Anything else (timeouts
            // above all) may have been accepted: unknown outcome.
            $neverSent = (bool) preg_match('/cURL error (6|7):/', $e->getMessage());
            Log::warning('whatsapp.send.connection_error', ['never_sent' => $neverSent]);

            return $neverSent
                ? WhatsAppSendResult::rejected('connection_failed', 'Could not connect to the WhatsApp Cloud API.', true)
                : WhatsAppSendResult::unknown('timeout', 'No response from the WhatsApp Cloud API; the message may or may not have been accepted.');
        } catch (Throwable $e) {
            Log::error('whatsapp.send.client_error', ['exception' => $e::class]);

            return WhatsAppSendResult::unknown('client_error', 'Unexpected error calling the WhatsApp Cloud API.');
        }

        if ($response->successful()) {
            $messageId = $response->json('messages.0.id');

            return is_string($messageId) && $messageId !== ''
                ? WhatsAppSendResult::accepted($messageId)
                : WhatsAppSendResult::unknown('missing_message_id', 'The WhatsApp Cloud API answered without a message id.');
        }

        $code = $response->json('error.code');

        if ($code === null) {
            // No Meta error object: a 5xx/proxy error page. Cannot know
            // whether the message was accepted.
            Log::warning('whatsapp.send.http_error', ['status' => $response->status()]);

            return $response->serverError()
                ? WhatsAppSendResult::unknown('http_'.$response->status(), 'The WhatsApp Cloud API returned HTTP '.$response->status().' without an error body.')
                : WhatsAppSendResult::rejected('http_'.$response->status(), 'The WhatsApp Cloud API rejected the request (HTTP '.$response->status().').', false);
        }

        $code = (string) $code;
        $title = (string) ($response->json('error.error_data.details') ?? $response->json('error.title') ?? $response->json('error.message') ?? 'WhatsApp Cloud API error');
        Log::warning('whatsapp.send.rejected', ['status' => $response->status(), 'code' => $code]);

        return WhatsAppSendResult::rejected($code, $title, in_array($code, self::RETRYABLE_CODES, true));
    }
}

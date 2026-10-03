<?php

namespace App\Support\WhatsApp;

/**
 * The central AFORO WhatsApp Cloud API configuration (config/whatsapp.php),
 * validated in one place (CARTA 9.1E). Secrets are read here and passed
 * only to MetaWhatsAppCloudApi / the webhook controller — never serialized,
 * logged or returned.
 *
 * missingForSending() lists missing/invalid setting NAMES only (never
 * values): with integration enabled but this non-empty, deliveries are
 * recorded as skipped (configuration_incomplete) instead of failing the
 * close — the Cierre Diario never depends on Meta being configured.
 */
final class WhatsAppConfig
{
    public const GRAPH_VERSION_PATTERN = '/^v\d+\.\d+$/';

    public function enabled(): bool
    {
        return (bool) config('whatsapp.enabled');
    }

    /**
     * @return array<int, string>
     */
    public function missingForSending(): array
    {
        $missing = [];

        if (! $this->enabled()) {
            $missing[] = 'WHATSAPP_CLOUD_ENABLED';
        }

        if (! preg_match(self::GRAPH_VERSION_PATTERN, (string) config('whatsapp.graph_api_version'))) {
            $missing[] = 'WHATSAPP_GRAPH_API_VERSION';
        }

        foreach ([
            'WHATSAPP_PHONE_NUMBER_ID' => 'phone_number_id',
            'WHATSAPP_ACCESS_TOKEN' => 'access_token',
            'WHATSAPP_TEMPLATE_NAME' => 'template_name',
            'WHATSAPP_TEMPLATE_LANGUAGE' => 'template_language',
            'AFORO_WEB_URL' => 'web_url',
        ] as $env => $key) {
            if (blank(config("whatsapp.{$key}"))) {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    public function readyToSend(): bool
    {
        return $this->missingForSending() === [];
    }

    public function messagesUrl(): string
    {
        return rtrim((string) config('whatsapp.graph_base_url'), '/').'/'.config('whatsapp.graph_api_version').'/'.config('whatsapp.phone_number_id').'/messages';
    }

    public function accessToken(): string
    {
        return (string) config('whatsapp.access_token');
    }

    public function phoneNumberId(): string
    {
        return (string) config('whatsapp.phone_number_id');
    }

    public function wabaId(): ?string
    {
        $id = config('whatsapp.waba_id');

        return blank($id) ? null : (string) $id;
    }

    public function templateName(): string
    {
        return (string) config('whatsapp.template_name');
    }

    public function templateLanguage(): string
    {
        return (string) config('whatsapp.template_language');
    }

    public function verifyToken(): ?string
    {
        $token = config('whatsapp.webhook_verify_token');

        return blank($token) ? null : (string) $token;
    }

    public function appSecret(): ?string
    {
        $secret = config('whatsapp.app_secret');

        return blank($secret) ? null : (string) $secret;
    }

    public function timeout(): int
    {
        return (int) config('whatsapp.timeout', 10);
    }

    public function connectTimeout(): int
    {
        return (int) config('whatsapp.connect_timeout', 5);
    }

    public function reportUrl(int $dayCloseId): string
    {
        return rtrim((string) config('whatsapp.web_url'), '/').str_replace('{id}', (string) $dayCloseId, (string) config('whatsapp.report_path'));
    }
}

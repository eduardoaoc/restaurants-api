<?php

namespace App\Support\WhatsApp;

/**
 * One outgoing template message (CARTA 9.1E): recipient in E.164 (with
 * "+", as Meta recommends), the approved template name/language and its
 * NAMED body parameters (parameter_name => text).
 */
final class WhatsAppTemplateMessage
{
    /**
     * @param  array<string, string>  $bodyParameters
     */
    public function __construct(
        public readonly string $toE164,
        public readonly string $templateName,
        public readonly string $templateLanguage,
        public readonly array $bodyParameters,
    ) {}

    /**
     * The Cloud API JSON payload (POST /{version}/{phone-number-id}/messages).
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->toE164,
            'type' => 'template',
            'template' => [
                'name' => $this->templateName,
                'language' => ['code' => $this->templateLanguage],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(
                        fn (string $name, string $text) => ['type' => 'text', 'parameter_name' => $name, 'text' => $text],
                        array_keys($this->bodyParameters),
                        array_values($this->bodyParameters),
                    ),
                ]],
            ],
        ];
    }
}

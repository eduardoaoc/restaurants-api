<?php

namespace App\Exceptions\WhatsApp;

use RuntimeException;

/**
 * Domain refusals of the Cierre Diario WhatsApp delivery (CARTA 9.1E),
 * rendered as {"error": {"code", "message"}} with their own status —
 * same rendering shape as DayCloseException.
 */
class WhatsAppDeliveryException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function notAvailable(): self
    {
        return new self('WHATSAPP_NOT_AVAILABLE', 422, 'WhatsApp delivery is not configured on this AFORO installation.');
    }

    public static function disabled(): self
    {
        return new self('WHATSAPP_DISABLED', 422, 'WhatsApp delivery of the Cierre Diario is disabled for this restaurant.');
    }

    public static function recipientRequired(): self
    {
        return new self('RECIPIENT_REQUIRED', 422, 'There is no active WhatsApp recipient for this restaurant.');
    }

    public static function consentRequired(): self
    {
        return new self('CONSENT_REQUIRED', 422, 'The WhatsApp recipient has no valid consent.');
    }

    public static function alreadyPending(): self
    {
        return new self('DELIVERY_ALREADY_PENDING', 409, 'A WhatsApp delivery of this close is still pending.');
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', 409, 'This idempotency key was already used with a different request.');
    }

    /**
     * @return array<string, string>
     */
    public function toResponseError(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage()];
    }
}

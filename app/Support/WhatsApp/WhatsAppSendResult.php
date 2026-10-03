<?php

namespace App\Support\WhatsApp;

/**
 * Outcome of one provider call, classified for the retry policy:
 *
 *   accepted  — Meta returned a message id. Accepted, NOT delivered.
 *   rejected  — Meta answered with an error: the message was NOT accepted,
 *               so retrying cannot duplicate it. `retryable` follows
 *               Meta's documented transient codes (rate limits, 131000,
 *               131016, ...) or a connection that never reached Meta.
 *   unknown   — the request may or may not have been accepted (timeout,
 *               5xx without an error body, 2xx without a message id):
 *               NEVER retried automatically — a duplicate WhatsApp
 *               message is worse than a manual resend.
 */
final class WhatsAppSendResult
{
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $messageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorReason = null,
        public readonly bool $retryable = false,
    ) {}

    public static function accepted(string $messageId): self
    {
        return new self(self::ACCEPTED, messageId: $messageId);
    }

    public static function rejected(string $code, string $reason, bool $retryable): self
    {
        return new self(self::REJECTED, errorCode: $code, errorReason: $reason, retryable: $retryable);
    }

    public static function unknown(string $code, string $reason): self
    {
        return new self(self::UNKNOWN, errorCode: $code, errorReason: $reason);
    }
}

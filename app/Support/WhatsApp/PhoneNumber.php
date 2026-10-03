<?php

namespace App\Support\WhatsApp;

/**
 * Phone handling for report recipients (CARTA 9.1E). Strict E.164 only
 * ("+34612345678"): "+", a non-zero country digit, 8–15 digits in total —
 * no spaces, dashes or local formats are guessed.
 *
 * hash(): HMAC-SHA256 keyed with APP_KEY. A plain SHA-256 of a phone
 * number is reversible by brute force (the number space is tiny), so the
 * hash is keyed. mask(): the only form shown in API responses, audit
 * logs and delivery history — "+34 ••• •• 12 34".
 */
final class PhoneNumber
{
    public const E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public static function isValidE164(string $phone): bool
    {
        return (bool) preg_match(self::E164_PATTERN, $phone);
    }

    public static function hash(string $e164): string
    {
        return hash_hmac('sha256', $e164, (string) config('app.key'));
    }

    public static function mask(string $e164): string
    {
        $digits = substr($e164, 1);
        $last = substr($digits, -4);

        return '+'.substr($digits, 0, 2).' ••• •• '.substr($last, 0, 2).' '.substr($last, 2, 2);
    }

    /**
     * Strip anything that looks like a phone number from provider text
     * before it is stored or logged.
     */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/\+?\d[\d\s-]{6,}\d/', '[redacted]', $text);
    }
}

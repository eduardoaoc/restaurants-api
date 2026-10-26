<?php

namespace App\Support\Invitations;

use App\Models\Invitation;
use Illuminate\Support\Str;

/**
 * The only place an invitation secret is created.
 *
 * One token per send: issued when the invitation is created or resent
 * (inside that transaction, only its SHA-256 is stored) and handed to the
 * queued e-mail, whose payload is encrypted (InvitationNotification is
 * ShouldBeEncrypted). Retries or a duplicated execution of that job send the
 * SAME link and never touch the stored hash; issuing a new token (resend)
 * invalidates every link sent before it. Lookups hash the presented value;
 * the database never holds anything that can be replayed.
 */
class InvitationTokens
{
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** An unusable placeholder hash for a row whose first e-mail is not built yet. */
    public static function placeholderHash(): string
    {
        return self::hash(Str::random(64));
    }

    /** A new random token (64 alphanumeric chars). Persist only hash($token). */
    public static function generate(): string
    {
        return Str::random(64);
    }

    /** Issues a fresh token for an existing row, persisting only its hash. */
    public static function mint(Invitation $invitation): string
    {
        $token = self::generate();

        Invitation::query()->whereKey($invitation->id)->update(['token_hash' => self::hash($token)]);

        return $token;
    }

    public static function find(string $token): ?Invitation
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        return Invitation::query()->where('token_hash', self::hash($token))->first();
    }
}

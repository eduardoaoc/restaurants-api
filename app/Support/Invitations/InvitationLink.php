<?php

namespace App\Support\Invitations;

use RuntimeException;

/**
 * Builds the acceptance link from configuration only (config/invitations.php).
 */
class InvitationLink
{
    public static function for(#[\SensitiveParameter] string $token): string
    {
        $base = rtrim((string) config('invitations.web_url'), '/');

        if ($base === '' || ! preg_match('#^https?://[^/\s]+#i', $base)) {
            throw new RuntimeException('AFORO_WEB_URL is not configured; invitation links cannot be built.');
        }

        return $base.config('invitations.accept_path').'#token='.rawurlencode($token);
    }
}

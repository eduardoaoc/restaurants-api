<?php

/*
|--------------------------------------------------------------------------
| Invitations (owner onboarding + staff)
|--------------------------------------------------------------------------
|
| The link is always built from the trusted frontend URL below — never from
| the request (Host/Origin/Referer) and never from a client-supplied
| redirect. The token travels in the URL fragment (#token=...), which
| browsers never send to any server or put in a Referer header.
|
| MAIL_MAILER=log writes the whole e-mail, link included, to the log file:
| acceptable only as a local capture box. See
| docs/onboarding-invitations-report.md before enabling this anywhere else.
*/

return [
    // Same variable the WhatsApp Cierre link already uses (config/whatsapp.php).
    'web_url' => env('AFORO_WEB_URL'),
    'accept_path' => '/invitation',

    'expires_hours' => (int) env('AFORO_INVITATION_EXPIRES_HOURS', 72),

    // Minimum seconds between two sends of the same invitation.
    'resend_cooldown_seconds' => 60,

    // Queue for invitation and password-reset e-mails. null = default queue.
    // Locally a dedicated name keeps a mail worker from consuming anything
    // else that sits in the default queue (see docs/local-workers.md).
    'mail_queue' => env('AFORO_MAIL_QUEUE'),
];

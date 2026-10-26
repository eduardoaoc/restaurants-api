<?php

namespace App\Notifications;

use App\Models\RestaurantSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * Password-reset e-mail for Laravel's native password broker
 * (PasswordResetController passes it as the sendResetLink() callback).
 *
 * Same transport rules as InvitationNotification: queued on
 * config('invitations.mail_queue'), ShouldBeEncrypted (the broker's clear
 * token only exists inside the encrypted job and the message), link built
 * only from config('invitations.web_url') with the secret in the URL
 * fragment, which no server receives.
 */
class ResetPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public const PATH = '/reset-password';

    private const COPY = [
        RestaurantSettings::LOCALE_ES_ES => [
            'subject' => 'Restablece tu contraseña de AFORO',
            'line' => 'Hemos recibido una solicitud para restablecer la contraseña de tu cuenta de AFORO.',
            'action' => 'Elegir nueva contraseña',
            'expiry' => 'El enlace caduca en :minutes minutos y solo se puede usar una vez.',
            'ignore' => 'Si no lo has pedido tú, ignora este correo: tu contraseña no cambiará.',
        ],
        RestaurantSettings::LOCALE_CA_ES_VALENCIA => [
            'subject' => 'Restablix la teua contrasenya d\'AFORO',
            'line' => 'Hem rebut una sol·licitud per a restablir la contrasenya del teu compte d\'AFORO.',
            'action' => 'Triar una contrasenya nova',
            'expiry' => 'L\'enllaç caduca en :minutes minuts i només es pot usar una vegada.',
            'ignore' => 'Si no ho has demanat tu, ignora este correu: la teua contrasenya no canviarà.',
        ],
        RestaurantSettings::LOCALE_EN_GB => [
            'subject' => 'Reset your AFORO password',
            'line' => 'We received a request to reset the password of your AFORO account.',
            'action' => 'Choose a new password',
            'expiry' => 'The link expires in :minutes minutes and can only be used once.',
            'ignore' => 'If you did not ask for this, ignore this e-mail: your password will not change.',
        ],
    ];

    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        private readonly string $copyLocale = RestaurantSettings::DEFAULT_LOCALE,
    ) {
        $this->onQueue(config('invitations.mail_queue'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $copy = self::COPY[$this->copyLocale] ?? self::COPY[RestaurantSettings::DEFAULT_LOCALE];

        return (new MailMessage)
            ->subject($copy['subject'])
            ->line($copy['line'])
            ->action($copy['action'], self::url($this->token, self::emailOf($notifiable)))
            ->line(strtr($copy['expiry'], [':minutes' => (string) config('auth.passwords.users.expire')]))
            ->line($copy['ignore']);
    }

    /** The broker always notifies a User; any other notifiable falls back to its mail route. */
    private static function emailOf(object $notifiable): string
    {
        return method_exists($notifiable, 'getEmailForPasswordReset')
            ? $notifiable->getEmailForPasswordReset()
            : (string) $notifiable->routeNotificationFor('mail');
    }

    public static function url(#[\SensitiveParameter] string $token, string $email): string
    {
        $base = rtrim((string) config('invitations.web_url'), '/');

        if ($base === '' || ! preg_match('#^https?://[^/\s]+#i', $base)) {
            throw new RuntimeException('AFORO_WEB_URL is not configured; password reset links cannot be built.');
        }

        return $base.self::PATH.'#'.http_build_query(['token' => $token, 'email' => $email], '', '&', PHP_QUERY_RFC3986);
    }
}

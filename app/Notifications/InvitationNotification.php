<?php

namespace App\Notifications;

use App\Models\Invitation;
use App\Models\RestaurantSettings;
use App\Support\Invitations\InvitationLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * The invitation e-mail. Queued and ShouldBeEncrypted: the token issued for
 * this send travels inside the job, so the jobs/failed_jobs rows only hold
 * ciphertext (APP_KEY), never a usable link. Every attempt of the job —
 * retries, or a duplicated execution — sends the same link and never
 * changes the stored hash (the old design minted in toMail(), so a retry
 * silently invalidated an already-delivered e-mail).
 *
 * shouldSend() drops a job that became stale while waiting in the queue:
 * accepted/revoked/expired invitations, or a newer resend (higher
 * delivery_sequence, i.e. a newer token) that superseded this one.
 *
 * Routed to config('invitations.mail_queue') so a local worker can consume
 * e-mail without touching other jobs of the default queue.
 */
class InvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    private const COPY = [
        RestaurantSettings::LOCALE_ES_ES => [
            'owner_subject' => 'Te damos la bienvenida a AFORO',
            'staff_subject' => 'Te han invitado a un equipo en AFORO',
            'owner_line' => 'Te han invitado a gestionar :organization en AFORO.',
            'staff_line' => 'Te han invitado a unirte al equipo de :organization en AFORO.',
            'action' => 'Aceptar invitación',
            'expiry' => 'El enlace caduca el :date (UTC) y solo se puede usar una vez.',
            'ignore' => 'Si no esperabas esta invitación, puedes ignorar este correo.',
        ],
        RestaurantSettings::LOCALE_CA_ES_VALENCIA => [
            'owner_subject' => 'Et donem la benvinguda a AFORO',
            'staff_subject' => 'T\'han convidat a un equip en AFORO',
            'owner_line' => 'T\'han convidat a gestionar :organization en AFORO.',
            'staff_line' => 'T\'han convidat a unir-te a l\'equip de :organization en AFORO.',
            'action' => 'Acceptar la invitació',
            'expiry' => 'L\'enllaç caduca el :date (UTC) i només es pot usar una vegada.',
            'ignore' => 'Si no esperaves esta invitació, pots ignorar este correu.',
        ],
        RestaurantSettings::LOCALE_EN_GB => [
            'owner_subject' => 'Welcome to AFORO',
            'staff_subject' => 'You have been invited to a team on AFORO',
            'owner_line' => 'You have been invited to manage :organization on AFORO.',
            'staff_line' => 'You have been invited to join the :organization team on AFORO.',
            'action' => 'Accept invitation',
            'expiry' => 'The link expires on :date (UTC) and can only be used once.',
            'ignore' => 'If you were not expecting this invitation, you can ignore this e-mail.',
        ],
    ];

    public function __construct(
        public readonly int $invitationId,
        public readonly int $sequence,
        #[\SensitiveParameter] private readonly string $token,
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

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $invitation = Invitation::query()->find($this->invitationId);

        return $invitation !== null
            && $invitation->isUsable()
            && $invitation->delivery_sequence === $this->sequence;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = Invitation::query()->with('organization')->findOrFail($this->invitationId);
        $copy = self::COPY[$invitation->locale] ?? self::COPY[RestaurantSettings::DEFAULT_LOCALE];
        $organization = $invitation->organization->name;
        $isOwner = $invitation->type === Invitation::TYPE_OWNER;

        $url = InvitationLink::for($this->token);

        return (new MailMessage)
            ->subject($copy[$isOwner ? 'owner_subject' : 'staff_subject'])
            ->line(strtr($copy[$isOwner ? 'owner_line' : 'staff_line'], [':organization' => $organization]))
            ->action($copy['action'], $url)
            ->line(strtr($copy['expiry'], [':date' => $invitation->expires_at->utc()->format('d/m/Y H:i')]))
            ->line($copy['ignore']);
    }

    /**
     * Last attempt failed: record it so the inviter sees "failed" and can
     * resend. Only for the sequence this job was queued for.
     */
    public function failed(Throwable $exception): void
    {
        Invitation::query()
            ->whereKey($this->invitationId)
            ->where('delivery_sequence', $this->sequence)
            ->update(['delivery_status' => Invitation::DELIVERY_FAILED, 'delivery_failed_at' => now()]);
    }
}

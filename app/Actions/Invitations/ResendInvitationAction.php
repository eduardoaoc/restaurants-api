<?php

namespace App\Actions\Invitations;

use App\Exceptions\Invitations\InvitationException;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Invitations\InvitationMailer;
use App\Support\Invitations\InvitationTokens;
use Illuminate\Support\Facades\DB;

/**
 * Sends a pending invitation again — the recovery path for a failed or
 * lost e-mail and for an expired link. Same row, same organization, no new
 * user/restaurant/grant: the stored hash is replaced right away (every
 * earlier link stops working), the expiry is renewed and the delivery
 * sequence moves on so any older job still in the queue is dropped by
 * InvitationNotification::shouldSend().
 */
class ResendInvitationAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly InvitationMailer $mailer,
    ) {}

    public function execute(Invitation $invitation, User $actor, string $actorType): Invitation
    {
        $invitation = DB::transaction(function () use ($invitation, $actor, $actorType) {
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if (! $locked->isPending()) {
                throw InvitationException::notPending();
            }

            $cooldown = (int) config('invitations.resend_cooldown_seconds');
            $availableAt = $locked->last_queued_at?->copy()->addSeconds($cooldown);

            if ($availableAt !== null && $availableAt->isFuture()) {
                throw InvitationException::resendTooSoon(max(1, (int) ceil(now()->diffInSeconds($availableAt))));
            }

            $token = InvitationTokens::generate();

            $locked->update([
                'token_hash' => InvitationTokens::hash($token),
                'expires_at' => now()->addHours((int) config('invitations.expires_hours')),
                'delivery_status' => Invitation::DELIVERY_QUEUED,
                'delivery_sequence' => $locked->delivery_sequence + 1,
                'send_count' => $locked->send_count + 1,
                'last_queued_at' => now(),
                'delivery_failed_at' => null,
            ]);

            $restaurantIds = $locked->restaurantIds();

            $this->auditLogger->log(
                organizationId: $locked->organization_id,
                restaurantId: count($restaurantIds) === 1 ? $restaurantIds[0] : null,
                actorType: $actorType,
                actor: $actor,
                event: AuditLog::EVENT_INVITATION_RESENT,
                resourceType: AuditLog::RESOURCE_INVITATION,
                resourceId: $locked->id,
                metadata: ['type' => $locked->type, 'send_count' => $locked->send_count],
            );

            return $locked->withIssuedToken($token);
        });

        $this->mailer->queue($invitation);

        return $invitation->refresh();
    }
}

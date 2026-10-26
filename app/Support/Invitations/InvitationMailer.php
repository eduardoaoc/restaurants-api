<?php

namespace App\Support\Invitations;

use App\Models\Invitation;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Throwable;

/**
 * Queues the invitation e-mail for the invitation's CURRENT
 * delivery_sequence. Always called after the surrounding transaction has
 * committed, so a failure here can never roll back (or half-create) the
 * onboarding/invitation itself: it only flips delivery_status to failed,
 * and the inviter recovers with a resend — never with a second
 * registration.
 */
class InvitationMailer
{
    public function queue(Invitation $invitation): void
    {
        $token = $invitation->issuedToken()
            ?? throw new LogicException('Only an invitation created or resent in this request can be queued.');

        try {
            Notification::route('mail', $invitation->email)
                ->notify(new InvitationNotification($invitation->id, $invitation->delivery_sequence, $token));
        } catch (Throwable $e) {
            report($e);

            Invitation::query()
                ->whereKey($invitation->id)
                ->where('delivery_sequence', $invitation->delivery_sequence)
                ->update(['delivery_status' => Invitation::DELIVERY_FAILED, 'delivery_failed_at' => now()]);
        }
    }
}

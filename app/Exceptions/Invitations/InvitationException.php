<?php

namespace App\Exceptions\Invitations;

use RuntimeException;

/**
 * Domain errors of the onboarding/invitation flow, rendered as
 * {"error": {"code", "message", ...}} by bootstrap/app.php.
 *
 * Messages never name another organization, never say whether an e-mail
 * belongs to an account elsewhere, and never echo the token.
 */
class InvitationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('INVITATION_NOT_FOUND', 404, 'This invitation link is not valid.');
    }

    public static function expired(): self
    {
        return new self('INVITATION_EXPIRED', 410, 'This invitation has expired. Ask for a new one.');
    }

    public static function revoked(): self
    {
        return new self('INVITATION_REVOKED', 410, 'This invitation was cancelled.');
    }

    public static function alreadyAccepted(): self
    {
        return new self('INVITATION_ALREADY_ACCEPTED', 410, 'This invitation was already used.');
    }

    public static function accountExists(): self
    {
        return new self('INVITATION_ACCOUNT_EXISTS', 409, 'An account already exists for this invitation. Sign in with it to accept.');
    }

    public static function accountRequired(): self
    {
        return new self('INVITATION_ACCOUNT_REQUIRED', 409, 'There is no account for this invitation yet. Create your password to accept.');
    }

    public static function accountMismatch(): self
    {
        return new self('INVITATION_ACCOUNT_MISMATCH', 409, 'You are signed in with a different account. Sign out and open the invitation again.');
    }

    public static function accountSuspended(): self
    {
        return new self('INVITATION_ACCOUNT_SUSPENDED', 403, 'This account cannot accept invitations.');
    }

    public static function notAllowedForAccount(): self
    {
        return new self('INVITATION_NOT_ALLOWED_FOR_ACCOUNT', 409, 'This account cannot accept this invitation.');
    }

    public static function organizationUnavailable(): self
    {
        return new self('INVITATION_ORGANIZATION_UNAVAILABLE', 409, 'This organization is not available right now.');
    }

    public static function membershipConflict(): self
    {
        return new self('INVITATION_MEMBERSHIP_CONFLICT', 409, 'This account already has (or had) access to this organization. Ask the organization to review it.');
    }

    public static function assignmentConflict(): self
    {
        return new self('INVITATION_ASSIGNMENT_CONFLICT', 409, 'A restaurant or employee code in this invitation is no longer available. Ask for a new invitation.');
    }

    public static function alreadyPending(int $invitationId): self
    {
        return new self('INVITATION_ALREADY_PENDING', 409, 'There is already a pending invitation for this e-mail.', ['invitation_id' => $invitationId]);
    }

    public static function staffAlreadyMember(): self
    {
        return new self('STAFF_ALREADY_MEMBER', 409, 'This person is already part of the team.');
    }

    public static function staffMembershipInactive(): self
    {
        return new self('STAFF_MEMBERSHIP_INACTIVE', 409, 'This person was deactivated. Reactivate them from the team instead of inviting again.');
    }

    public static function notPending(): self
    {
        return new self('INVITATION_NOT_PENDING', 409, 'Only pending invitations can be changed.');
    }

    public static function resendTooSoon(int $retryAfter): self
    {
        return new self('INVITATION_RESEND_TOO_SOON', 429, 'Wait a moment before sending this invitation again.', ['retry_after' => $retryAfter]);
    }

    public static function ownerAlreadyActive(): self
    {
        return new self('ORGANIZATION_HAS_OWNER', 409, 'This organization already has an active owner.');
    }

    public static function organizationSuspended(): self
    {
        return new self('ORGANIZATION_SUSPENDED', 409, 'This organization is suspended.');
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', 409, 'This idempotency key was already used with a different request.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponseError(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage(), ...$this->details];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time invitation into an Organization, for an owner (created by a
 * platform admin during onboarding) or an operational staff member
 * (created by someone holding manage_users).
 *
 * Nothing here grants access by itself: organization_users / user_roles /
 * restaurant_users rows are created only by AcceptInvitationAction, inside
 * the acceptance transaction. The secret is never stored — token_hash is
 * the SHA-256 of a token minted when the e-mail is built (see
 * InvitationTokens), so neither this row, the queued job nor an audit entry
 * can be turned back into a usable link.
 *
 * `status` only moves pending → accepted | revoked. Expiry is derived from
 * expires_at (see state()) so that renewing a pending invitation on resend
 * never needs a status transition.
 */
#[Fillable([
    'organization_id', 'type', 'email', 'name', 'role_id', 'restaurant_assignments', 'locale',
    'token_hash', 'status', 'expires_at', 'delivery_status', 'delivery_sequence', 'send_count',
    'last_queued_at', 'last_sent_at', 'delivery_failed_at', 'invited_by_type', 'invited_by_user_id',
    'accepted_at', 'accepted_user_id', 'revoked_at', 'revoked_by_user_id',
])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    public const TYPE_OWNER = 'owner';

    public const TYPE_STAFF = 'staff';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    /** Presentation-only state: a pending invitation past expires_at. */
    public const STATE_EXPIRED = 'expired';

    public const DELIVERY_QUEUED = 'queued';

    /** Handed to the mail transport — never proof that it reached the inbox. */
    public const DELIVERY_SENT = 'sent';

    public const DELIVERY_FAILED = 'failed';

    public const INVITED_BY_PLATFORM_ADMIN = 'platform_admin';

    public const INVITED_BY_USER = 'user';

    /**
     * Clear token issued in THIS request (create/resend), kept only in
     * memory until InvitationMailer hands it to the encrypted job. A plain
     * declared property, never an Eloquent attribute: it is not persisted,
     * serialized into toArray()/JSON, or reloaded by refresh().
     */
    private ?string $issuedToken = null;

    public function withIssuedToken(#[\SensitiveParameter] string $token): static
    {
        $this->issuedToken = $token;

        return $this;
    }

    public function issuedToken(): ?string
    {
        return $this->issuedToken;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'restaurant_assignments' => 'array',
            'expires_at' => 'datetime',
            'last_queued_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'delivery_failed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'delivery_sequence' => 'integer',
            'send_count' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** True only while the invitation can still be accepted. */
    public function isUsable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    /** pending | expired | accepted | revoked */
    public function state(): string
    {
        if ($this->isPending() && $this->isExpired()) {
            return self::STATE_EXPIRED;
        }

        return $this->status;
    }

    /**
     * Restaurant ids of a staff invitation, in the order they were sent.
     *
     * @return array<int, int>
     */
    public function restaurantIds(): array
    {
        return collect($this->restaurant_assignments ?? [])
            ->pluck('restaurant_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}

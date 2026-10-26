<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Invitation;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Invitation as seen by whoever manages it (platform admin for owners,
 * manage_users holders for staff). Never includes the token or its hash.
 *
 * `delivery.status` is queued | sent | failed: "sent" only means the mail
 * transport accepted the message — the UI must never present it as
 * "received". Acceptance is `state = accepted`.
 *
 * @mixin Invitation
 */
class InvitationResource extends JsonResource
{
    /**
     * Restaurant names keyed by id, preloaded by the controller for the
     * whole collection (avoids one query per invitation).
     *
     * @var array<int, string>
     */
    public static array $restaurantNames = [];

    /**
     * @param  iterable<Invitation>  $invitations
     */
    public static function preloadRestaurantNames(iterable $invitations): void
    {
        $ids = collect($invitations)->flatMap(fn (Invitation $invitation) => $invitation->restaurantIds())->unique()->values();

        self::$restaurantNames = $ids->isEmpty()
            ? []
            : Restaurant::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cooldown = (int) config('invitations.resend_cooldown_seconds');

        return [
            'id' => $this->id,
            'type' => $this->type,
            'email' => $this->email,
            'name' => $this->name,
            'state' => $this->state(),
            'role' => $this->role?->slug,
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ]),
            'restaurants' => collect($this->restaurant_assignments ?? [])->map(fn (array $assignment) => [
                'id' => (int) $assignment['restaurant_id'],
                'name' => self::$restaurantNames[(int) $assignment['restaurant_id']] ?? null,
                'sub_id' => $assignment['sub_id'],
            ])->values()->all(),
            'delivery' => [
                'status' => $this->delivery_status,
                'send_count' => $this->send_count,
                'last_queued_at' => $this->last_queued_at?->toIso8601String(),
                'last_sent_at' => $this->last_sent_at?->toIso8601String(),
                'failed_at' => $this->delivery_failed_at?->toIso8601String(),
                'resend_available_at' => $this->isPending() && $this->last_queued_at
                    ? $this->last_queued_at->copy()->addSeconds($cooldown)->toIso8601String()
                    : null,
            ],
            'expires_at' => $this->expires_at->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

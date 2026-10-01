<?php

namespace App\Support\Activity;

use App\Models\RestaurantActivityEvent;
use App\Models\User;

/**
 * Who performed an activity. A staff actor is snapshotted (id + name at
 * that moment); an anonymous QR customer never has an id or a name — no
 * customer identity is invented or stored.
 */
final class ActivityActor
{
    private function __construct(
        public readonly string $type,
        public readonly ?int $userId,
        public readonly ?string $name,
    ) {}

    public static function staff(User $user): self
    {
        return new self(RestaurantActivityEvent::ACTOR_STAFF, $user->id, $user->name);
    }

    public static function customer(): self
    {
        return new self(RestaurantActivityEvent::ACTOR_CUSTOMER, null, null);
    }
}

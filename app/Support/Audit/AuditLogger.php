<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Single, explicit entry point for recording domain audit events. Records
 * only what a caller names explicitly (event, resource, whitelisted
 * changes/metadata) — it never inspects a Model's dirty attributes or
 * decides on its own which events matter. See the Bloco 16 report for why
 * a generic Model-saved Observer was deliberately not used instead.
 *
 * Call sites are expected to be inside the same DB::transaction() as the
 * mutation they're recording, so a rolled-back operation never leaves a
 * misleading audit trail behind.
 */
class AuditLogger
{
    /**
     * @param  array<string, array{old: mixed, new: mixed}>|null  $changes
     * @param  array<string, mixed>|null  $metadata
     */
    public function log(
        int $organizationId,
        ?int $restaurantId,
        string $actorType,
        ?User $actor,
        string $event,
        string $resourceType,
        ?int $resourceId,
        ?array $changes = null,
        ?array $metadata = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'organization_id' => $organizationId,
            'restaurant_id' => $restaurantId,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actorType,
            'event' => $event,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}

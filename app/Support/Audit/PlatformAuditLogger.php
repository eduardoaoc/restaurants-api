<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Thin, explicit wrapper around AuditLogger for platform-level mutations —
 * fixes actor_type to ACTOR_PLATFORM_ADMIN and always carries the
 * mandatory `reason` (see PlatformReasonRequest-style validation on every
 * platform status/plan-change request) into metadata, so every call site
 * in the Platform controllers looks the same and can never forget either.
 *
 * Reuses the same AuditLog table/model as tenant events — see the Bloco 0
 * report for why a second audit table would have been duplication, not
 * separation.
 */
class PlatformAuditLogger
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, array{old: mixed, new: mixed}>|null  $changes
     * @param  array<string, mixed>|null  $metadata
     */
    public function log(
        User $actor,
        string $event,
        string $resourceType,
        ?int $resourceId,
        ?int $organizationId,
        ?int $restaurantId,
        string $reason,
        ?array $changes = null,
        ?array $metadata = null,
    ): AuditLog {
        return $this->auditLogger->log(
            organizationId: $organizationId,
            restaurantId: $restaurantId,
            actorType: AuditLog::ACTOR_PLATFORM_ADMIN,
            actor: $actor,
            event: $event,
            resourceType: $resourceType,
            resourceId: $resourceId,
            changes: $changes,
            metadata: array_merge(['reason' => $reason], $metadata ?? []),
        );
    }
}

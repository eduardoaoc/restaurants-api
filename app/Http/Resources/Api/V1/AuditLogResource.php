<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 *
 * Never exposes organization_id (redundant — the endpoint is already
 * tenant-scoped), actor email, or a live-loaded copy of the original
 * resource — resource is always {type, id}, never the Model itself, to
 * avoid N+1 and to keep working even if the referenced row is gone.
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'actor' => [
                'type' => $this->actor_type,
                'user' => $this->actor ? [
                    'id' => $this->actor->id,
                    'name' => $this->actor->name,
                ] : null,
            ],
            'restaurant' => $this->restaurant ? [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ] : null,
            'resource' => [
                'type' => $this->resource_type,
                'id' => $this->resource_id,
            ],
            'changes' => $this->changes,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}

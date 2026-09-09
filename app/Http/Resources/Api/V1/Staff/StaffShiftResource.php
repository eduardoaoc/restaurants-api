<?php

namespace App\Http\Resources\Api\V1\Staff;

use App\Models\StaffShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffShift
 *
 * `is_active` is always derived (ended_at === null), never a stored flag.
 * `role` is the user's operational role scoped to THIS shift's restaurant
 * (see StaffShift::operationalRole) — never an organization-wide role.
 * Never exposes email/phone/permissions.
 */
class StaffShiftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null,
            'role' => $this->operationalRoleSlug(),
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'is_active' => $this->isActive(),
            'started_by_user_id' => $this->started_by_user_id,
            'ended_by_user_id' => $this->ended_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

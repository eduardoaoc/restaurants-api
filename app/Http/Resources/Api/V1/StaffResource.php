<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * `status` here is the staff member's OPERATIONAL membership in the
 * active organization (OrganizationUser::STATUSES — active/inactive), read
 * from the organization_users pivot row that StaffController::staffQuery()
 * eager-loads scoped to that one organization. It is deliberately never
 * users.status (the global, platform-only suspension flag) — that field
 * is never exposed by the Staff API at all, to keep the two authorities
 * visibly separate for the frontend (Passo 2.8B-FIX).
 */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->roles->first();
        $membership = $this->organizations->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $membership?->pivot?->status,
            'role' => $role ? [
                'id' => $role->id,
                'slug' => $role->slug,
            ] : null,
            'restaurants' => $this->restaurants->map(fn ($restaurant) => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'sub_id' => $restaurant->pivot->sub_id,
            ])->values(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

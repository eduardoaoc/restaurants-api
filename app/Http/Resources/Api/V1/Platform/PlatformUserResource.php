<?php

namespace App\Http\Resources\Api\V1\Platform;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * Deliberately never delegates to User::toArray()/JsonResource default
 * behavior — every field is named explicitly so password, remember_token,
 * and anything added to the User model later is never exposed here by
 * accident (see the Bloco 0 report's sensitive-data section).
 */
class PlatformUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'organizations' => $this->whenLoaded('organizations', fn () => $this->organizations->map(fn ($organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ])),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')->unique()->values()),
            'platform_roles' => $this->whenLoaded('platformRoles', fn () => $this->platformRoles->pluck('slug')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

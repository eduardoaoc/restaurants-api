<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->roles->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
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

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Models\WaiterCall;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WaiterCall
 *
 * Actor sub-objects never include email, only {id, name} — same
 * convention as TableRequestResource.
 */
class WaiterCallResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'table_session_id' => $this->table_session_id,
            'restaurant_id' => $this->restaurant_id,
            'status' => $this->status,
            'waiter' => $this->actor($this->waiter),
            'called_by' => $this->actor($this->calledBy),
            'created_at' => $this->created_at,
            'acknowledged_at' => $this->acknowledged_at,
            'acknowledged_by' => $this->actor($this->acknowledgedBy),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function actor(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TableRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * table_session_id is deliberately not exposed here — not useful to the
 * operational contract yet, and easy to add later if a real need shows up.
 * Actor sub-objects never include email, only {id, name}.
 *
 * @mixin TableRequest
 */
class TableRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'restaurant' => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ],
            'table' => [
                'id' => $this->table->id,
                'name' => $this->table->name,
                'number' => $this->table->number,
            ],
            'note' => $this->note,
            'created_at' => $this->created_at,
            'acknowledged_at' => $this->acknowledged_at,
            'acknowledged_by' => $this->actor($this->acknowledgedBy),
            'completed_at' => $this->completed_at,
            'completed_by' => $this->actor($this->completedBy),
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $this->actor($this->cancelledBy),
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

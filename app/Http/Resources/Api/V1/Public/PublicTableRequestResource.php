<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\TableRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean public projection of a just-created table request: no
 * restaurant_id/table_session_id/staff identities — the client only needs
 * to know what it asked for and its current status.
 *
 * @mixin TableRequest
 */
class PublicTableRequestResource extends JsonResource
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
            'created_at' => $this->created_at,
        ];
    }
}

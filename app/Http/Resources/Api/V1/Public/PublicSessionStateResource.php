<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\TableSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * Public projection of a table's session state. Wraps a nullable
 * TableSession (already scoped to non-closed statuses by the caller) and
 * never exposes its identity — only whether it is active, and its status.
 */
class PublicSessionStateResource extends JsonResource
{
    /**
     * A non-null placeholder is passed to the parent constructor even when
     * there is no session: JsonResource::filter() collapses any nested
     * resource whose ->resource is null to a bare `null`, which would break
     * the stable `{active, status}` contract required when there is no
     * active session.
     */
    public function __construct(private readonly ?TableSession $session)
    {
        parent::__construct($session ?? new stdClass);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'active' => $this->session !== null,
            'status' => $this->session?->status,
        ];
    }
}

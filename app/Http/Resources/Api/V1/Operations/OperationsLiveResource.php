<?php

namespace App\Http\Resources\Api\V1\Operations;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin pass-through wrapper around
 * BuildRestaurantOperationsSnapshotAction's already-fully-shaped output
 * (Bloco 5) — the Action owns the response contract; this Resource exists
 * only so the Controller stays a plain "resolve -> authorize -> build ->
 * wrap" flow, matching every other endpoint in this codebase.
 */
class OperationsLiveResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(private readonly array $snapshot)
    {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->snapshot;
    }
}

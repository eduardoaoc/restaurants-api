<?php

namespace App\Actions\Public;

use App\Exceptions\Public\PublicTableNotFoundException;
use App\Models\Table;

class ResolvePublicTableAction
{
    /**
     * Resolve a public token to a servable Table, loaded with its active
     * restaurant, that restaurant's menu, and its active session (if any).
     *
     * The context (Restaurant/Organization) is derived exclusively from the
     * token itself — never from an id supplied by the caller.
     */
    public function execute(string $publicToken): Table
    {
        $table = Table::query()
            ->where('public_token', $publicToken)
            ->where('status', 'active')
            ->whereHas('restaurant', fn ($query) => $query->where('status', 'active'))
            ->with(['restaurant.menu', 'activeSession'])
            ->first();

        if (! $table) {
            throw new PublicTableNotFoundException;
        }

        return $table;
    }
}

<?php

namespace App\Actions\Tables;

use App\Exceptions\TableSessionConflictException;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CloseTableAction
{
    public function execute(TableSession $session, User $closedBy): TableSession
    {
        return DB::transaction(function () use ($session, $closedBy) {
            if (! $session->isActive()) {
                throw new TableSessionConflictException('This table session is already closed.');
            }

            $session->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by_user_id' => $closedBy->id,
            ]);

            return $session->fresh();
        });
    }
}

<?php

namespace App\Actions\Tables;

use App\Events\Realtime\TableSessionGuestCountChanged;
use App\Exceptions\GuestCountVersionConflictException;
use App\Exceptions\TableSessionConflictException;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;

class UpdateTableSessionGuestCountAction
{
    public function execute(TableSession $session, int $guestCount, int $expectedVersion): TableSession
    {
        return DB::transaction(function () use ($session, $guestCount, $expectedVersion) {
            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw new TableSessionConflictException('This table session is closed.');
            }

            if ($locked->guest_count_version !== $expectedVersion) {
                throw new GuestCountVersionConflictException('The table guest count changed. Reload it and confirm your change again.');
            }

            // Lock the physical table row while reading capacity so a
            // concurrent structural update cannot race this validation.
            $table = Table::query()->whereKey($locked->table_id)
                ->where('restaurant_id', $locked->restaurant_id)
                ->lockForUpdate()->firstOrFail();

            if ($locked->guest_count === $guestCount) {
                return $locked;
            }

            if ($table->capacity !== null && $guestCount > $table->capacity) {
                throw new \Illuminate\Validation\ValidationException(
                    validator: validator(['guest_count' => $guestCount], ['guest_count' => ['lte:'.$table->capacity]])
                );
            }

            $previousGuestCount = $locked->guest_count;
            $locked->update([
                'guest_count' => $guestCount,
                'guest_count_version' => $locked->guest_count_version + 1,
            ]);

            TableSessionGuestCountChanged::dispatch(
                $locked->restaurant_id,
                $locked->table_id,
                $locked->id,
                $previousGuestCount,
                $guestCount,
            );

            return $locked->fresh();
        });
    }
}

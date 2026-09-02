<?php

namespace App\Actions\Tables;

use App\Exceptions\TableSessionConflictException;
use App\Models\AuditLog;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opens a new session for a table. The application-level check guards the
 * common path with a clear 409; the partial unique index on table_sessions
 * (table_id where status <> 'closed') is the real safety net against a race
 * between two concurrent "open" requests for the same table.
 */
class OpenTableAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Table $table, User $openedBy, int $guestCount): TableSession
    {
        return DB::transaction(function () use ($table, $openedBy, $guestCount) {
            if ($table->activeSession()->exists()) {
                throw new TableSessionConflictException('This table already has an active session.');
            }

            try {
                $session = TableSession::query()->create([
                    'restaurant_id' => $table->restaurant_id,
                    'table_id' => $table->id,
                    'opened_by_user_id' => $openedBy->id,
                    'guest_count' => $guestCount,
                    'status' => 'occupied',
                    'opened_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw new TableSessionConflictException('This table already has an active session.', previous: $e);
            }

            $this->auditLogger->log(
                organizationId: $table->restaurant->organization_id,
                restaurantId: $table->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $openedBy,
                event: AuditLog::EVENT_TABLE_SESSION_OPENED,
                resourceType: AuditLog::RESOURCE_TABLE_SESSION,
                resourceId: $session->id,
                metadata: ['table_id' => $table->id, 'status' => $session->status],
            );

            return $session;
        });
    }
}

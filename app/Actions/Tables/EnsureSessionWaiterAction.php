<?php

namespace App\Actions\Tables;

use App\Models\RestaurantActivityEvent;
use App\Models\StaffShift;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Activity\ActivityActor;
use App\Support\Activity\RestaurantActivityRecorder;
use App\Support\Activity\RestaurantActivityType;
use App\Support\Tables\WaiterAssignmentEligibility;
use App\Support\Tables\WaiterAssignmentLock;
use Illuminate\Support\Facades\DB;

/** Reconcile only this session on a command, never on a GET. */
class EnsureSessionWaiterAction
{
    public function execute(TableSession $session, ?User $actor = null, string $source = 'qr_entry', bool $preferActor = false): TableSession
    {
        return DB::transaction(function () use ($session, $actor, $source, $preferActor) {
            WaiterAssignmentLock::acquire($session->restaurant_id);
            $session = TableSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (! $session->isActive() || $session->isPaid()) {
                return $session;
            }
            $restaurant = $session->restaurant;
            $eligible = fn (User $user) => WaiterAssignmentEligibility::ineligibilityReason($user, $restaurant->organization, $restaurant) === null;
            $current = $session->assigned_waiter_user_id
                ? User::query()->whereKey($session->assigned_waiter_user_id)->sharedLock()->first() : null;
            // Preserve explicit/manual eligibility (off-shift is an existing alert).
            if ($current && $eligible($current)) {
                return $session;
            }

            $candidate = $preferActor && $actor ? User::query()->whereKey($actor->id)->sharedLock()->first() : null;
            if (! $candidate || ! $eligible($candidate)) {
                // Presence is a real StaffShift, never socket state or last login.
                $candidates = User::query()->whereIn('id', StaffShift::query()
                    ->select('user_id')->where('restaurant_id', $restaurant->id)->whereNull('ended_at'))
                    ->orderBy('id')->sharedLock()->get()->filter($eligible);
                $demand = TableSession::query()->where('restaurant_id', $restaurant->id)->where('status', '!=', 'closed')
                    ->whereNotNull('assigned_waiter_user_id')->selectRaw('assigned_waiter_user_id, COUNT(*) AS demand')
                    ->groupBy('assigned_waiter_user_id')->pluck('demand', 'assigned_waiter_user_id');
                // Existing immutable audited timestamps cover manual AND automatic assignments.
                $last = DB::table('audit_logs')->where('restaurant_id', $restaurant->id)
                    ->whereIn('event', ['table_session.waiter_assigned', 'table_session.waiter_reassigned'])
                    ->selectRaw("metadata->>'new_waiter_user_id' AS waiter_id, MAX(created_at) AS last_assigned")
                    ->groupByRaw("metadata->>'new_waiter_user_id'")->pluck('last_assigned', 'waiter_id');
                $candidate = $candidates->sortBy(fn (User $user) => [
                    (int) ($demand[$user->id] ?? 0), $last[$user->id] ?? '', $user->id,
                ])->first();
            }
            if ($candidate) {
                return app(AssignWaiterAction::class)->execute($session, $candidate, $actor, $source);
            }
            if ($session->assigned_waiter_user_id !== null) {
                $session = app(UnassignWaiterAction::class)->execute($session, $actor, $source);
            }
            $this->recordPending($session, $actor, $source);

            return $session;
        });
    }

    /** The same session lock protects one notification per pending occurrence.
     * Shared manager feed is durable even if there are no eligible managers now.
     */
    public function recordPending(TableSession $session, ?User $actor, string $source): void
    {
        $last = RestaurantActivityEvent::query()->where('table_session_id', $session->id)
            ->whereIn('type', [RestaurantActivityType::TABLE_WAITER_ASSIGNED, RestaurantActivityType::TABLE_ASSIGNMENT_PENDING])
            ->latest('id')->value('type');
        if ($last === RestaurantActivityType::TABLE_ASSIGNMENT_PENDING) {
            return;
        }
        app(RestaurantActivityRecorder::class)->record(
            restaurantId: $session->restaurant_id,
            type: RestaurantActivityType::TABLE_ASSIGNMENT_PENDING,
            actor: $actor ? ActivityActor::staff($actor) : ActivityActor::customer(),
            table: $session->table,
            tableSessionId: $session->id,
            metadata: ['source' => $source],
        );
    }
}

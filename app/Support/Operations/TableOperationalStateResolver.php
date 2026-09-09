<?php

namespace App\Support\Operations;

/**
 * The single canonical interpretation of "what state is this Table in
 * right now" (Bloco 5) — primary_status for the map's main render, flags
 * for everything else simultaneously true. Never duplicated in a
 * Controller/Resource/health/alerts — every one of those consumes this
 * class's output instead of re-deriving it.
 *
 * Precedence (highest first): bill_requested > waiter_requested > ready >
 * waiting_approval > preparing > occupied > free. `preparing` is a
 * deliberate umbrella over Order statuses confirmed/accepted/preparing —
 * the table map has no separate visual state for "kitchen accepted but
 * hasn't started" vs "actively cooking"; that distinction is preserved in
 * full in the kitchen snapshot's counts_by_status (see
 * BuildRestaurantOperationsSnapshotAction), just not at the table-icon
 * level. See the Bloco 5 report.
 */
class TableOperationalStateResolver
{
    /**
     * @var array<int, string>
     */
    public const PRIMARY_STATUSES = [
        'bill_requested', 'waiter_requested', 'ready', 'waiting_approval', 'preparing', 'occupied', 'free',
    ];

    /**
     * @param  array{
     *     has_active_session: bool,
     *     has_bill_requested: bool,
     *     has_waiter_requested: bool,
     *     has_responsible_waiter_called: bool,
     *     has_ready_order: bool,
     *     has_waiting_approval_order: bool,
     *     has_preparing_order: bool,
     *     is_unassigned: bool,
     *     assigned_waiter_off_shift: bool,
     *     assigned_waiter_suspended: bool,
     * }  $state
     * @return array{primary_status: string, flags: array<int, string>}
     */
    public static function resolve(array $state): array
    {
        if (! $state['has_active_session']) {
            return ['primary_status' => 'free', 'flags' => []];
        }

        $primaryStatus = match (true) {
            $state['has_bill_requested'] => 'bill_requested',
            $state['has_waiter_requested'] => 'waiter_requested',
            $state['has_ready_order'] => 'ready',
            $state['has_waiting_approval_order'] => 'waiting_approval',
            $state['has_preparing_order'] => 'preparing',
            default => 'occupied',
        };

        $flags = [];

        foreach ([
            'has_bill_requested' => 'bill_requested',
            'has_waiter_requested' => 'waiter_requested',
            'has_responsible_waiter_called' => 'responsible_waiter_called',
            'has_ready_order' => 'ready_order',
            'has_waiting_approval_order' => 'waiting_approval',
            'has_preparing_order' => 'preparing_order',
            'is_unassigned' => 'unassigned',
            'assigned_waiter_off_shift' => 'assigned_waiter_off_shift',
            'assigned_waiter_suspended' => 'assigned_waiter_suspended',
        ] as $stateKey => $flag) {
            if ($state[$stateKey]) {
                $flags[] = $flag;
            }
        }

        return ['primary_status' => $primaryStatus, 'flags' => $flags];
    }
}

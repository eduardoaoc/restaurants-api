<?php

namespace Tests\Unit\Support\Operations;

use App\Support\Operations\TableOperationalStateResolver;
use Tests\TestCase;

/**
 * Bloco 5 — canonical table state precedence: bill_requested >
 * waiter_requested > ready > waiting_approval > preparing > occupied >
 * free.
 */
class TableOperationalStateResolverTest extends TestCase
{
    private function state(array $overrides = []): array
    {
        return array_merge([
            'has_active_session' => true,
            'has_bill_requested' => false,
            'has_waiter_requested' => false,
            'has_responsible_waiter_called' => false,
            'has_ready_order' => false,
            'has_waiting_approval_order' => false,
            'has_preparing_order' => false,
            'is_unassigned' => false,
            'assigned_waiter_off_shift' => false,
            'assigned_waiter_suspended' => false,
        ], $overrides);
    }

    public function test_no_active_session_is_free(): void
    {
        $result = TableOperationalStateResolver::resolve($this->state(['has_active_session' => false]));

        $this->assertSame('free', $result['primary_status']);
        $this->assertSame([], $result['flags']);
    }

    public function test_active_session_with_no_events_is_occupied(): void
    {
        $result = TableOperationalStateResolver::resolve($this->state());

        $this->assertSame('occupied', $result['primary_status']);
        $this->assertSame([], $result['flags']);
    }

    public function test_preparing_order(): void
    {
        $result = TableOperationalStateResolver::resolve($this->state(['has_preparing_order' => true]));

        $this->assertSame('preparing', $result['primary_status']);
        $this->assertContains('preparing_order', $result['flags']);
    }

    public function test_ready_order(): void
    {
        $result = TableOperationalStateResolver::resolve($this->state(['has_ready_order' => true]));

        $this->assertSame('ready', $result['primary_status']);
        $this->assertContains('ready_order', $result['flags']);
    }

    public function test_waiting_approval(): void
    {
        $result = TableOperationalStateResolver::resolve($this->state(['has_waiting_approval_order' => true]));

        $this->assertSame('waiting_approval', $result['primary_status']);
    }

    public function test_waiter_requested(): void
    {
        $result = TableOperationalStateResolver::resolve($this->state(['has_waiter_requested' => true]));

        $this->assertSame('waiter_requested', $result['primary_status']);
    }

    public function test_bill_requested(): void
    {
        $result = TableOperationalStateResolver::resolve($this->state(['has_bill_requested' => true]));

        $this->assertSame('bill_requested', $result['primary_status']);
    }

    /**
     * bill_requested outranks everything, even when a ready order and a
     * waiter request are simultaneously true — the precedence order.
     */
    public function test_precedence_bill_requested_beats_everything(): void
    {
        $result = TableOperationalStateResolver::resolve($this->state([
            'has_bill_requested' => true,
            'has_waiter_requested' => true,
            'has_ready_order' => true,
            'has_waiting_approval_order' => true,
            'has_preparing_order' => true,
        ]));

        $this->assertSame('bill_requested', $result['primary_status']);
        // All simultaneously-true facts still surface as flags.
        $this->assertContains('bill_requested', $result['flags']);
        $this->assertContains('waiter_requested', $result['flags']);
        $this->assertContains('ready_order', $result['flags']);
        $this->assertContains('waiting_approval', $result['flags']);
        $this->assertContains('preparing_order', $result['flags']);
    }

    public function test_precedence_ready_beats_waiting_approval_and_preparing(): void
    {
        $result = TableOperationalStateResolver::resolve($this->state([
            'has_ready_order' => true,
            'has_waiting_approval_order' => true,
            'has_preparing_order' => true,
        ]));

        $this->assertSame('ready', $result['primary_status']);
    }

    public function test_unassigned_flag_and_off_shift_and_suspended_flags(): void
    {
        $result = TableOperationalStateResolver::resolve($this->state(['is_unassigned' => true]));
        $this->assertContains('unassigned', $result['flags']);
        $this->assertSame('occupied', $result['primary_status']);

        $result = TableOperationalStateResolver::resolve($this->state(['assigned_waiter_off_shift' => true]));
        $this->assertContains('assigned_waiter_off_shift', $result['flags']);

        $result = TableOperationalStateResolver::resolve($this->state(['assigned_waiter_suspended' => true]));
        $this->assertContains('assigned_waiter_suspended', $result['flags']);
    }

    public function test_responsible_waiter_called_flag(): void
    {
        $result = TableOperationalStateResolver::resolve($this->state(['has_responsible_waiter_called' => true]));

        $this->assertContains('responsible_waiter_called', $result['flags']);
        $this->assertSame('occupied', $result['primary_status']);
    }
}

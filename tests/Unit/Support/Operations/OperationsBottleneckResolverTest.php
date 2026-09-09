<?php

namespace Tests\Unit\Support\Operations;

use App\Support\Operations\OperationsBottleneckResolver;
use Tests\TestCase;

/**
 * Bloco 5 — deterministic bottleneck: group by type, pick highest
 * severity, then highest affected_count, then oldest age. Never depends
 * on input array order.
 */
class OperationsBottleneckResolverTest extends TestCase
{
    public function test_no_alerts_returns_null(): void
    {
        $this->assertNull(OperationsBottleneckResolver::resolve([]));
    }

    public function test_single_alert_becomes_the_bottleneck(): void
    {
        $result = OperationsBottleneckResolver::resolve([
            ['type' => 'order_ready', 'severity' => 'info', 'age_seconds' => 30],
        ]);

        $this->assertSame([
            'type' => 'order_ready',
            'severity' => 'info',
            'affected_count' => 1,
            'oldest_age_seconds' => 30,
        ], $result);
    }

    public function test_higher_severity_wins_over_more_affected(): void
    {
        $result = OperationsBottleneckResolver::resolve([
            ['type' => 'order_ready', 'severity' => 'info', 'age_seconds' => 10],
            ['type' => 'order_ready', 'severity' => 'info', 'age_seconds' => 20],
            ['type' => 'order_ready', 'severity' => 'info', 'age_seconds' => 30],
            ['type' => 'assigned_waiter_suspended', 'severity' => 'critical', 'age_seconds' => 5],
        ]);

        $this->assertSame('assigned_waiter_suspended', $result['type']);
        $this->assertSame('critical', $result['severity']);
        $this->assertSame(1, $result['affected_count']);
    }

    public function test_same_severity_more_affected_wins(): void
    {
        $result = OperationsBottleneckResolver::resolve([
            ['type' => 'bill_request_pending', 'severity' => 'warning', 'age_seconds' => 100],
            ['type' => 'active_table_unassigned', 'severity' => 'warning', 'age_seconds' => 50],
            ['type' => 'active_table_unassigned', 'severity' => 'warning', 'age_seconds' => 60],
        ]);

        $this->assertSame('active_table_unassigned', $result['type']);
        $this->assertSame(2, $result['affected_count']);
        $this->assertSame(60, $result['oldest_age_seconds']);
    }

    public function test_same_severity_same_count_oldest_wins(): void
    {
        $result = OperationsBottleneckResolver::resolve([
            ['type' => 'bill_request_pending', 'severity' => 'warning', 'age_seconds' => 100],
            ['type' => 'active_table_unassigned', 'severity' => 'warning', 'age_seconds' => 500],
        ]);

        $this->assertSame('active_table_unassigned', $result['type']);
        $this->assertSame(500, $result['oldest_age_seconds']);
    }

    public function test_order_of_input_does_not_affect_the_result(): void
    {
        $alerts = [
            ['type' => 'bill_request_pending', 'severity' => 'warning', 'age_seconds' => 100],
            ['type' => 'active_table_unassigned', 'severity' => 'warning', 'age_seconds' => 500],
        ];

        $forward = OperationsBottleneckResolver::resolve($alerts);
        $reversed = OperationsBottleneckResolver::resolve(array_reverse($alerts));

        $this->assertSame($forward, $reversed);
    }

    public function test_full_tie_breaks_alphabetically_by_type(): void
    {
        $result = OperationsBottleneckResolver::resolve([
            ['type' => 'order_ready', 'severity' => 'info', 'age_seconds' => 10],
            ['type' => 'bill_request_pending', 'severity' => 'info', 'age_seconds' => 10],
        ]);

        $this->assertSame('bill_request_pending', $result['type']);
    }
}

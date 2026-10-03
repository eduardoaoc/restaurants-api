<?php

namespace Tests\Unit\Support\Operations;

use App\Support\Operations\OperationHealthCalculator;
use Tests\TestCase;

/**
 * Bloco 5 — deterministic health score: 100 - 20*critical - 8*warning -
 * 3*info, clamped [0,100]; level 80-100 healthy, 50-79 attention, 0-49
 * critical.
 */
class OperationHealthCalculatorTest extends TestCase
{
    public function test_no_alerts_scores_100(): void
    {
        $this->assertSame(100, OperationHealthCalculator::score([]));
        $this->assertSame('healthy', OperationHealthCalculator::level(100));
    }

    public function test_one_warning_scores_92(): void
    {
        $score = OperationHealthCalculator::score([['severity' => 'warning']]);

        $this->assertSame(92, $score);
        $this->assertSame('healthy', OperationHealthCalculator::level($score));
    }

    public function test_one_critical_and_one_warning_scores_72(): void
    {
        $score = OperationHealthCalculator::score([
            ['severity' => 'critical'],
            ['severity' => 'warning'],
        ]);

        $this->assertSame(72, $score);
        $this->assertSame('attention', OperationHealthCalculator::level($score));
    }

    public function test_one_info_scores_97(): void
    {
        $this->assertSame(97, OperationHealthCalculator::score([['severity' => 'info']]));
    }

    public function test_score_never_goes_below_zero(): void
    {
        $alerts = array_fill(0, 20, ['severity' => 'critical']);

        $this->assertSame(0, OperationHealthCalculator::score($alerts));
    }

    public function test_score_never_exceeds_100(): void
    {
        $this->assertSame(100, OperationHealthCalculator::score([]));
    }

    public function test_level_boundaries(): void
    {
        $this->assertSame('healthy', OperationHealthCalculator::level(80));
        $this->assertSame('attention', OperationHealthCalculator::level(79));
        $this->assertSame('attention', OperationHealthCalculator::level(50));
        $this->assertSame('critical', OperationHealthCalculator::level(49));
        $this->assertSame('critical', OperationHealthCalculator::level(0));
    }
}

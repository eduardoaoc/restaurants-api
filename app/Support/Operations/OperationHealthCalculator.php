<?php

namespace App\Support\Operations;

/**
 * Deterministic operational health score for the live snapshot (Bloco 5).
 * No AI, no persistence — a pure function of the alert list's severities.
 * Weights are centralized here, never scattered across a Controller.
 */
class OperationHealthCalculator
{
    private const WEIGHTS = [
        'critical' => 20,
        'warning' => 8,
        'info' => 3,
    ];

    private const MIN_SCORE = 0;

    private const MAX_SCORE = 100;

    /**
     * @param  array<int, array{severity: string}>  $alerts
     */
    public static function score(array $alerts): int
    {
        $score = self::MAX_SCORE;

        foreach ($alerts as $alert) {
            $score -= self::WEIGHTS[$alert['severity']] ?? 0;
        }

        return max(self::MIN_SCORE, min(self::MAX_SCORE, $score));
    }

    /**
     * 80-100 healthy, 50-79 attention, 0-49 critical. Returns a stable
     * code — never a localized string; the frontend translates.
     */
    public static function level(int $score): string
    {
        return match (true) {
            $score >= 80 => 'healthy',
            $score >= 50 => 'attention',
            default => 'critical',
        };
    }
}

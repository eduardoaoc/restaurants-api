<?php

namespace App\Support\Operations;

/**
 * Deterministically picks the single most pressing operational bottleneck
 * from the alert list (Bloco 5) — no AI, no human-language text; the
 * backend returns codes/counts, the frontend phrases it for a human.
 *
 * Alerts are grouped by `type` (one group per operational fact category),
 * then the group is chosen by: highest severity, then highest affected
 * count, then oldest (largest age_seconds). A final alphabetical-by-type
 * tie-breaker guarantees a single deterministic winner regardless of the
 * alerts array's own (insertion) order, per the report.
 */
class OperationsBottleneckResolver
{
    private const SEVERITY_RANK = [
        'critical' => 3,
        'warning' => 2,
        'info' => 1,
    ];

    /**
     * @param  array<int, array{type: string, severity: string, age_seconds: int}>  $alerts
     * @return array{type: string, severity: string, affected_count: int, oldest_age_seconds: int}|null
     */
    public static function resolve(array $alerts): ?array
    {
        if ($alerts === []) {
            return null;
        }

        $groups = [];

        foreach ($alerts as $alert) {
            $type = $alert['type'];

            if (! isset($groups[$type])) {
                $groups[$type] = [
                    'type' => $type,
                    'severity' => $alert['severity'],
                    'affected_count' => 0,
                    'oldest_age_seconds' => 0,
                ];
            }

            $groups[$type]['affected_count']++;
            $groups[$type]['oldest_age_seconds'] = max($groups[$type]['oldest_age_seconds'], $alert['age_seconds']);
        }

        $best = null;

        foreach ($groups as $group) {
            if ($best === null || self::isBetter($group, $best)) {
                $best = $group;
            }
        }

        return $best;
    }

    /**
     * @param  array{type: string, severity: string, affected_count: int, oldest_age_seconds: int}  $candidate
     * @param  array{type: string, severity: string, affected_count: int, oldest_age_seconds: int}  $current
     */
    private static function isBetter(array $candidate, array $current): bool
    {
        $candidateRank = self::SEVERITY_RANK[$candidate['severity']] ?? 0;
        $currentRank = self::SEVERITY_RANK[$current['severity']] ?? 0;

        if ($candidateRank !== $currentRank) {
            return $candidateRank > $currentRank;
        }

        if ($candidate['affected_count'] !== $current['affected_count']) {
            return $candidate['affected_count'] > $current['affected_count'];
        }

        if ($candidate['oldest_age_seconds'] !== $current['oldest_age_seconds']) {
            return $candidate['oldest_age_seconds'] > $current['oldest_age_seconds'];
        }

        return $candidate['type'] < $current['type'];
    }
}

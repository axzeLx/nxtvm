<?php

namespace App\Checkup;

/**
 * Score 0–100. Every scored check (weight > 0, applicable, not informational) earns its weight × check score.
 * Check score = 1 − deduction; each severity deducts factor × (0.3 + 0.7 × affected share):
 * critical factor 1, warning 0.5. One affected object already costs 30 % of the factor, all of them the full factor.
 */
final class Score
{
    private const FACTOR = ['crit' => 1.0, 'warn' => 0.5];

    /** Score (0–1) of one check result; null when it does not count. */
    public static function check(array $r): ?float
    {
        if (($r['weight'] ?? 0) <= 0 || in_array($r['status'], ['na', 'error'], true)) {
            return null;
        }
        $total = max(1, $r['total'], $r['crit'] + $r['warn']);
        $deduct = 0.0;
        foreach (self::FACTOR as $sev => $f) {
            if ($r[$sev] > 0) {
                $deduct += $f * (0.3 + 0.7 * min(1, $r[$sev] / $total));
            }
        }

        return max(0.0, 1 - $deduct);
    }

    /**
     * Weighted score over check results.
     *
     * @param  iterable<array>  $results  results of one or more runs
     */
    public static function of(iterable $results, ?string $category = null): ?int
    {
        $sum = $weights = 0.0;
        foreach ($results as $r) {
            if ($category !== null && $r['category'] !== $category) {
                continue;
            }
            $s = self::check($r);
            if ($s !== null) {
                $sum += $r['weight'] * $s;
                $weights += $r['weight'];
            }
        }

        return $weights > 0 ? (int) round(100 * $sum / $weights) : null;
    }

    public static function grade(?int $score): string
    {
        return match (true) {
            $score === null => '–',
            $score >= 90 => 'A', $score >= 80 => 'B', $score >= 70 => 'C', $score >= 60 => 'D',
            default => 'F',
        };
    }

    /** ok | warn | crit | unknown, for colours */
    public static function level(?int $score): string
    {
        return match (true) {
            $score === null => 'unknown',
            $score >= 80 => 'ok', $score >= 60 => 'warn',
            default => 'crit',
        };
    }
}

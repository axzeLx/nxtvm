<?php

namespace App\Checkup;

use Closure;

/** Definition of one checkup check. */
final class Check
{
    /**
     * @param  string  $severity  default severity of a finding: crit | warn | info (info never lowers the score)
     * @param  int  $weight  default weight in the score (0–5)
     * @param  array<string, array{0: int|float|string, 1: string}>  $params  name → [default, label]
     * @param  Closure(Context, array, Result): void  $run
     */
    public function __construct(
        public readonly string $key,
        public readonly string $category,
        public readonly string $title,
        public readonly string $severity,
        public readonly int $weight,
        public readonly string $advice,
        public readonly array $params,
        public readonly Closure $run,
        public readonly bool $perFinding = false,   // findings are unrelated to each other (alarms): "Ask AI" per row
    ) {}
}

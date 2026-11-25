<?php

namespace App\Checkup;

/** A category's checks. */
abstract class CheckSet
{
    abstract public function category(): string;

    /** @return list<Check> */
    abstract public function checks(): array;

    protected function check(string $key, string $title, string $severity, int $weight, string $advice, array $params, callable $run, bool $perFinding = false): Check
    {
        return new Check($key, $this->category(), $title, $severity, $severity === 'info' ? 0 : $weight, $advice, $params, $run(...), $perFinding);
    }

    /** Lower-case set of a host's running / "on" services by key */
    protected static function service(array $data, string $key): ?array
    {
        foreach ($data['services'] ?? [] as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }

        return null;
    }

    protected static function days(int|float $seconds): int
    {
        return (int) floor($seconds / 86400);
    }

    /** "a, b, c and 4 more" */
    protected static function list(iterable $items, int $max = 5): string
    {
        $items = collect($items)->values();
        $shown = $items->take($max)->join(', ');

        return $items->count() > $max ? $shown.' '.__('and :n more', ['n' => $items->count() - $max]) : $shown;
    }
}

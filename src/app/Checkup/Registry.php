<?php

namespace App\Checkup;

/** All checks, keyed by check key, in category order. */
final class Registry
{
    private const SETS = [
        Checks\SecurityChecks::class, Checks\HostChecks::class, Checks\ClusterChecks::class, Checks\VmChecks::class,
        Checks\SnapshotChecks::class, Checks\StorageChecks::class, Checks\NetworkChecks::class,
        Checks\LicenseChecks::class, Checks\AlarmChecks::class, Checks\VCenterChecks::class,
    ];

    private ?array $checks = null;

    /** @return array<string, Check> */
    public function all(): array
    {
        if ($this->checks === null) {
            $this->checks = [];
            foreach (self::SETS as $set) {
                foreach ((new $set)->checks() as $c) {
                    $this->checks[$c->key] = $c;
                }
            }
        }

        return $this->checks;
    }

    public function get(string $key): ?Check
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, list<Check>> category → checks */
    public function byCategory(): array
    {
        $out = array_fill_keys(array_keys(Category::all()), []);
        foreach ($this->all() as $c) {
            $out[$c->category][] = $c;
        }

        return $out;
    }
}

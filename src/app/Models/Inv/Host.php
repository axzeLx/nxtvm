<?php

namespace App\Models\Inv;

/** Inventory table inv_hosts (latest state, replaced on every collection). */
class Host extends InvModel
{
    protected $table = 'inv_hosts';

    /** Physical cores per CPU package. */
    public function coresPerCpu(): ?int
    {
        return $this->cpu_sockets ? intdiv((int) $this->cpu_cores, $this->cpu_sockets) : null;
    }

    /** Cores counted for per-core licensing: each CPU counts at least nxtvm.license_min_cores. */
    public function licensedCores(): int
    {
        if (! $this->cpu_sockets) {
            return (int) $this->cpu_cores;
        }

        return $this->cpu_sockets * max(config('nxtvm.license_min_cores'), $this->coresPerCpu());
    }
}

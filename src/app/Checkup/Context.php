<?php

namespace App\Checkup;

use App\Models\Inv;
use App\Models\VCenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** The collected inventory of one vCenter as the checks see it (exclusions applied, loaded once). */
final class Context
{
    private array $cache = [];

    /** @param  array<string, list<string>>  $exclude  object type → name patterns (* wildcard, case-insensitive) */
    public function __construct(public readonly VCenter $vc, private readonly array $exclude = []) {}

    public function hosts(): Collection
    {
        return $this->load('hosts', Inv\Host::class, 'host');
    }

    /** VMs without templates */
    public function vms(): Collection
    {
        return $this->cache['vms'] ??= $this->load('allvms', Inv\Vm::class, 'vm')->reject->template->values();
    }

    public function poweredOnVms(): Collection
    {
        return $this->cache['vmsOn'] ??= $this->vms()->where('power_state', 'poweredOn')->values();
    }

    public function clusters(): Collection
    {
        return $this->load('clusters', Inv\Cluster::class);
    }

    public function datastores(): Collection
    {
        return $this->load('datastores', Inv\Datastore::class, 'datastore');
    }

    public function networks(): Collection
    {
        return $this->load('networks', Inv\Network::class, 'network');
    }

    public function dvswitches(): Collection
    {
        return $this->load('dvswitches', Inv\DvSwitch::class);
    }

    public function licenses(): Collection
    {
        return $this->load('licenses', Inv\License::class);
    }

    public function certificates(): Collection
    {
        return $this->load('certificates', Inv\Certificate::class);
    }

    public function alarms(): Collection
    {
        return $this->load('alarms', Inv\Alarm::class);
    }

    public function host(?string $moid): ?Inv\Host
    {
        return ($this->cache['hostByMoid'] ??= $this->hosts()->keyBy('moid'))->get($moid);
    }

    public function datastore(?string $moid): ?Inv\Datastore
    {
        return ($this->cache['dsByMoid'] ??= $this->datastores()->keyBy('moid'))->get($moid);
    }

    /** Connected hosts of a cluster */
    public function clusterHosts(Inv\Cluster $c): Collection
    {
        return $this->hosts()->where('cluster_moid', $c->moid)->where('connection_state', 'connected')->values();
    }

    public function clusterVms(Inv\Cluster $c): Collection
    {
        $hosts = $this->hosts()->where('cluster_moid', $c->moid)->pluck('moid')->flip();

        return $this->poweredOnVms()->filter(fn ($v) => $hosts->has($v->host_moid))->values();
    }

    /** vCenter-level warnings of the last collection (e.g. "Licenses: no permission to read.") */
    public function warnings(): array
    {
        return $this->vc->inventory['warnings'] ?? [];
    }

    public function excluded(string $type, ?string $name): bool
    {
        $name = Str::lower((string) $name);
        foreach ($this->exclude[$type] ?? [] as $p) {
            if (Str::is(Str::lower($p), $name)) {
                return true;
            }
        }

        return false;
    }

    private function load(string $key, string $model, ?string $type = null): Collection
    {
        $order = ['certificates' => 'owner', 'alarms' => 'alarm', 'licenses' => 'name'][$key] ?? 'name';

        return $this->cache[$key] ??= $model::where('vcenter_id', $this->vc->id)->orderBy($order)->get()
            ->when($type, fn ($c) => $c->reject(fn ($o) => $this->excluded($type, $o->name))->values());
    }
}

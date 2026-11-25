<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesVCenter;
use App\Models\Inv;
use App\Support\VcScope;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class InventoryController extends Controller
{
    use ScopesVCenter;

    public function hosts(): View
    {
        return view('inventory.hosts', [
            'vcenters' => $this->vcenters(),
            'hosts' => $this->scoped(Inv\Host::query())->orderBy('cluster')->orderBy('name')->get(),
        ]);
    }

    public function host(int $vc, string $moid): View
    {
        $host = Inv\Host::where('vcenter_id', $vc)->where('moid', $moid)->firstOrFail();
        app(VcScope::class)->set($vc);
        $vms = Inv\Vm::where('vcenter_id', $host->vcenter_id)->where('host_moid', $host->moid)->orderBy('name')->get();
        $datastores = Inv\Datastore::where('vcenter_id', $host->vcenter_id)->whereIn('moid', $host->data['datastores'] ?? [])->orderBy('name')->get();
        $cert = Inv\Certificate::where('vcenter_id', $host->vcenter_id)->where('owner_moid', $host->moid)->first();
        $license = collect(Inv\License::where('vcenter_id', $host->vcenter_id)->get())
            ->first(fn ($l) => collect($l->data['assigned'] ?? [])->contains('entity', $host->moid));

        return view('inventory.host', compact('host', 'vms', 'datastores', 'cert', 'license') + ['vcenter' => $host->vcenter]);
    }

    /** Datastore detail: summary, mounts per host, multipath state of its LUNs, and the VMs that use it. */
    public function datastore(int $vc, string $moid): View
    {
        $ds = Inv\Datastore::where('vcenter_id', $vc)->where('moid', $moid)->firstOrFail();
        app(VcScope::class)->set($vc);

        $hosts = Inv\Host::where('vcenter_id', $vc)->get()->keyBy('moid');
        $mounts = collect($ds->data['hosts'] ?? [])
            ->map(fn ($m) => $m + ['row' => $hosts[$m['host']] ?? null])
            ->sortBy(fn ($m) => $m['row']?->name ?? $m['host'], SORT_NATURAL)->values();

        // multipath state of the datastore's extents (LUNs), as each host sees them
        $extents = $ds->data['vmfs']['extents'] ?? [];
        $paths = $mounts->flatMap(fn ($m) => collect($m['row']?->data['storage_paths'] ?? [])
            ->whereIn('canonical', $extents)->map(fn ($l) => $l + ['host' => $m['row']]))->values();

        // VMs with files on this datastore; the disk capacity that lives here
        $vms = Inv\Vm::where('vcenter_id', $vc)->whereJsonContains('data->datastores', $moid)->orderBy('name')->get()
            ->each(function ($vm) use ($ds) {
                $disks = collect($vm->data['disks'] ?? [])->where('datastore', $ds->moid);
                $vm->setAttribute('ds_disks', $disks->count());
                $vm->setAttribute('ds_bytes', $disks->sum('capacity_bytes'));
                $vm->setAttribute('ds_home', str_starts_with($vm->data['vmx'] ?? '', '['.$ds->name.']'));
            });

        return view('inventory.datastore', compact('ds', 'mounts', 'paths', 'vms', 'extents') + ['vcenter' => $ds->vcenter]);
    }

    public function vms(): View
    {
        return view('inventory.vms', [
            'vcenters' => $this->vcenters(),
            'vms' => $this->scoped(Inv\Vm::query())->orderBy('name')->get(),
        ]);
    }

    public function vm(int $vc, string $moid): View
    {
        $vm = Inv\Vm::where('vcenter_id', $vc)->where('moid', $moid)->firstOrFail();
        app(VcScope::class)->set($vc);
        $dsNames = Inv\Datastore::where('vcenter_id', $vm->vcenter_id)->pluck('name', 'moid');

        // NIC network names → network page
        $netMoids = Inv\Network::where('vcenter_id', $vm->vcenter_id)->pluck('moid', 'name');

        return view('inventory.vm', ['vm' => $vm, 'vcenter' => $vm->vcenter, 'dsNames' => $dsNames, 'netMoids' => $netMoids]);
    }

    /** Network (port group) detail: general facts, the hosts that carry it (vSwitch / VLAN per host) and the VMs on it. */
    public function network(int $vc, string $moid): View
    {
        $network = Inv\Network::where('vcenter_id', $vc)->where('moid', $moid)->firstOrFail();
        app(VcScope::class)->set($vc);

        // VMs with a NIC on this network (NICs reference the network by name)
        $vms = Inv\Vm::where('vcenter_id', $vc)
            ->whereRaw("data->'nics' @> ?::jsonb", [json_encode([['network' => $network->name]])])
            ->get()->sortBy('name', SORT_NATURAL)->values();

        $perHost = $network->data['per_host'] ?? [];
        $hostMoids = $network->kind === 'standard' ? array_keys($perHost) : ($network->data['hosts'] ?? []);
        $hosts = Inv\Host::where('vcenter_id', $vc)->whereIn('moid', $hostMoids)->get()->sortBy('name', SORT_NATURAL)->values();
        // the VLAN most hosts use: other values are marked
        $majorVlan = collect($perHost)->pluck('vlan')->countBy()->sortDesc()->keys()->first();

        return view('inventory.network', compact('network', 'vms', 'hosts', 'perHost', 'majorVlan') + ['vcenter' => $network->vcenter]);
    }

    public function datastores(): View
    {
        $datastores = $this->scoped(Inv\Datastore::query())->orderBy('name')->get();
        $hosts = $this->scoped(Inv\Host::query())->orderBy('name')->get();

        return view('inventory.datastores', [
            'vcenters' => $this->vcenters(),
            'datastores' => $datastores,
            // local datastores belong to one host by definition — listed above, not in the matrix
            'matrix' => $this->matrix($hosts, $datastores->reject(fn ($ds) => $ds->data['vmfs']['local'] ?? false), function ($ds) {
                $m = [];
                foreach ($ds->data['hosts'] ?? [] as $h) {
                    $m[$h['host']] = ['ok' => $h['mounted'] && $h['accessible'], 'mode' => $h['mode']];
                }

                return $m;
            }, perObject: true),
        ]);
    }

    public function networks(): View
    {
        $networks = $this->scoped(Inv\Network::query())->orderBy('name')->get();
        $hosts = $this->scoped(Inv\Host::query())->orderBy('name')->get();

        return view('inventory.networks', [
            'vcenters' => $this->vcenters(),
            'networks' => $networks,
            'dvswitches' => $this->scoped(Inv\DvSwitch::query())->orderBy('name')->get(),
            'matrix' => $this->matrix($hosts, $networks, function ($n) {
                $m = [];
                if ($n->kind === 'standard') {
                    foreach ($n->data['per_host'] ?? [] as $hostMoid => $pg) {
                        $m[$hostMoid] = ['ok' => true, 'vlan' => (string) $pg['vlan'], 'switch' => $pg['vswitch']];
                    }
                } else {
                    foreach ($n->data['hosts'] ?? [] as $hostMoid) {
                        $m[$hostMoid] = ['ok' => true, 'vlan' => $n->vlan, 'switch' => $n->switch];
                    }
                }

                return $m;
            }),
            'pnics' => $this->pnicGrid($hosts),
        ]);
    }

    /**
     * Physical adapters per host: columns vmnic0…N (union over the hosts), each cell the adapter's facts plus how a
     * switch uses it (vSwitch / dvSwitch uplink, active / standby / unused).
     */
    private function pnicGrid(Collection $hosts): array
    {
        $rows = [];
        $devices = [];
        foreach ($hosts->sortBy(fn ($h) => [$h->vcenter_id, $h->cluster === null ? 1 : 0, $h->cluster, $h->name]) as $h) {
            $net = $h->data['network'] ?? [];
            $roles = [];   // device → ["vSwitch0 · active", …]
            foreach ($net['vswitches'] ?? [] as $vs) {
                foreach ($vs['uplinks'] ?? [] as $dev) {
                    $role = in_array($dev, $vs['active_nics'] ?? [], true) ? 'active'
                        : (in_array($dev, $vs['standby_nics'] ?? [], true) ? 'standby' : 'unused');
                    $roles[$dev][] = $vs['name'].' · '.$role;
                }
            }
            foreach ($net['dvs'] ?? [] as $dvs) {
                foreach ($dvs['pnics'] ?? $dvs['uplinks'] ?? [] as $dev) {
                    if (is_string($dev)) {
                        $roles[$dev][] = ($dvs['name'] ?? 'dvSwitch').' · '.__('uplink');
                    }
                }
            }
            $cells = [];
            foreach ($net['pnics'] ?? [] as $p) {
                $devices[$p['device']] = true;
                $cells[$p['device']] = $p + ['roles' => $roles[$p['device']] ?? []];
            }
            $rows[] = ['host' => $h, 'cells' => $cells];
        }
        $devices = array_keys($devices);
        natsort($devices);

        return ['devices' => array_values($devices), 'rows' => $rows];
    }

    public function licenses(): View
    {
        return view('inventory.licenses', [
            'vcenters' => $this->vcenters(),
            'licenses' => $this->scoped(Inv\License::query())->orderBy('name')->get(),
        ]);
    }

    public function certificates(): View
    {
        return view('inventory.certificates', [
            'vcenters' => $this->vcenters(),
            'certificates' => $this->scoped(Inv\Certificate::query())->orderBy('valid_to')->get(),
        ]);
    }

    public function components(): View
    {
        return view('inventory.components', [
            'vcenters' => $this->vcenters(),
            'shown' => $this->vcenters(),
            'clusters' => $this->scoped(Inv\Cluster::query())->orderBy('name')->get(),
            'alarms' => $this->scoped(Inv\Alarm::query())->orderByDesc('triggered_at')->get(),
            'hostsByCluster' => $this->scoped(Inv\Host::query())->get()->groupBy(fn ($h) => $h->vcenter_id.'|'.$h->cluster_moid),
        ]);
    }

    /**
     * One object × host matrix for all clusters. Host columns are ordered by vCenter / cluster / name and
     * grouped under a cluster header; each row is (cluster, object) and only has cells for that cluster's hosts.
     * Returns ['groups' => [[label, vcenter, cluster, hosts]], 'hosts' => Collection, 'rows' => [[cluster, vcenter, object, cells, members]]]
     */
    /**
     * Object × host matrix, host columns grouped by cluster.
     * Default: one row per object and cluster (port groups can differ per cluster).
     * $perObject: one row per object spanning every cluster it is used in (datastores); 'cluster' is then the list
     * of those clusters, and hosts of clusters that do not use the object are not members (grey cells).
     */
    private function matrix(Collection $hosts, Collection $objects, callable $cellsOf, bool $perObject = false): array
    {
        $cells = $objects->mapWithKeys(fn ($o) => [$o->id => $cellsOf($o)]);
        $groups = $hosts->groupBy(fn ($h) => $h->vcenter_id.'|'.($h->cluster ?? ''))
            ->map(function ($groupHosts, $key) {
                [$vcId, $cluster] = explode('|', $key, 2);

                return ['vcenter' => (int) $vcId, 'cluster' => $cluster ?: null, 'hosts' => $groupHosts->sortBy('name', SORT_NATURAL)->values()];
            })
            ->sortBy(fn ($g) => [$g['vcenter'], $g['cluster'] === null ? 1 : 0, $g['cluster']])
            ->values();

        $rows = [];
        if ($perObject) {
            foreach ($objects->sortBy(fn ($o) => [$o->vcenter_id, $o->name]) as $o) {
                $used = $groups->where('vcenter', $o->vcenter_id)
                    ->filter(fn ($g) => array_intersect_key($cells[$o->id], array_flip($g['hosts']->pluck('moid')->all())));
                if ($used->isEmpty()) {
                    continue;
                }
                $memberHosts = $used->flatMap(fn ($g) => $g['hosts'])->values();
                $members = array_flip($memberHosts->pluck('moid')->all());
                $rows[] = ['vcenter' => $o->vcenter_id, 'cluster' => $used->pluck('cluster')->all(), 'object' => $o,
                    'cells' => array_intersect_key($cells[$o->id], $members), 'members' => $members, 'hosts' => $memberHosts];
            }

            return ['groups' => $groups->all(), 'hosts' => $groups->flatMap(fn ($g) => $g['hosts'])->values(), 'rows' => $rows];
        }
        foreach ($groups as $g) {
            $members = array_flip($g['hosts']->pluck('moid')->all());
            foreach ($objects->where('vcenter_id', $g['vcenter']) as $o) {
                $c = array_intersect_key($cells[$o->id], $members);
                if ($c) {
                    $rows[] = ['vcenter' => $g['vcenter'], 'cluster' => $g['cluster'], 'object' => $o, 'cells' => $c, 'members' => $members, 'hosts' => $g['hosts']];
                }
            }
        }

        return ['groups' => $groups->all(), 'hosts' => $groups->flatMap(fn ($g) => $g['hosts'])->values(), 'rows' => $rows];
    }
}

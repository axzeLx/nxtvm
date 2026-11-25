<?php

namespace App\Checkup\Checks;

use App\Checkup\CheckSet;
use App\Checkup\Context;
use App\Checkup\Result;

class NetworkChecks extends CheckSet
{
    public function category(): string
    {
        return 'network';
    }

    public function checks(): array
    {
        $connected = fn (Context $c) => $c->hosts()->where('connection_state', 'connected')->values();

        return [
            $this->check('pg_missing', __('Network not defined on all cluster hosts'), 'crit', 4,
                __('Create the port group (same name and VLAN) on every host of the cluster, or move to a distributed switch. VMs on it cannot be vMotioned or HA-restarted to the hosts without it.'), [],
                function (Context $c, array $p, Result $r) {
                    $nets = $c->networks()->where('kind', 'standard');
                    $r->total($nets->count() + $c->dvswitches()->count());
                    $clusters = $c->clusters()->map(fn ($cl) => [$cl, $c->clusterHosts($cl)->pluck('name', 'moid')])->filter(fn ($x) => $x[1]->count() > 1);
                    $gap = function ($present) use ($clusters) {
                        $out = [];
                        foreach ($clusters as [$cl, $hosts]) {
                            $on = $hosts->keys()->filter(fn ($m) => $present->has($m));
                            if ($on->isNotEmpty() && $on->count() < $hosts->count()) {
                                $out[] = __(':hosts (cluster :cl)', ['hosts' => self::list($hosts->except($on->all())->values()), 'cl' => $cl->name]);
                            }
                        }

                        return $out;
                    };
                    foreach ($nets as $n) {
                        if ($missing = $gap(collect($n->data['hosts'] ?? [])->flip())) {
                            $r->obj($n, __('Missing on :list.', ['list' => implode('; ', $missing)]).($n->vm_count ? ' '.__(':n VMs use it.', ['n' => $n->vm_count]) : ''),
                                $n->vm_count ? 'crit' : 'warn');
                        }
                    }
                    foreach ($c->dvswitches() as $s) {
                        if ($missing = $gap(collect($s->data['hosts'] ?? [])->pluck('host')->flip())) {
                            $r->obj($s, __('Hosts not joined to the distributed switch: :list.', ['list' => implode('; ', $missing)]));
                        }
                    }
                }),

            $this->check('pg_vlan', __('Same port group, different VLAN'), 'crit', 4,
                __('A port group name must map to the same VLAN on every host; otherwise VMs lose connectivity after vMotion or HA restart.'), [],
                function (Context $c, array $p, Result $r) {
                    $nets = $c->networks()->where('kind', 'standard');
                    $r->total($nets->count());
                    foreach ($nets as $n) {
                        $by = collect($n->data['per_host'] ?? [])->groupBy('vlan', true);
                        if ($by->count() > 1) {
                            $r->obj($n, $by->map(fn ($hosts, $vlan) => 'VLAN '.$vlan.': '.self::list(collect($hosts)->keys()->map(fn ($m) => $c->host($m)?->name ?? $m), 3))->join('; '));
                        }
                    }
                }),

            $this->check('pg_unused', __('Unused port groups'), 'info', 0,
                __('Remove port groups that no VM or VMkernel adapter uses to keep the network configuration clear.'), [],
                function (Context $c, array $p, Result $r) {
                    $vmk = $c->hosts()->flatMap(fn ($h) => collect($h->data['network']['vmks'] ?? [])->pluck('portgroup'))->filter()->flip();
                    $r->total($c->networks()->count());
                    foreach ($c->networks()->where('vm_count', 0) as $n) {
                        if (! $vmk->has($n->name)) {
                            $r->obj($n, __('No VM or VMkernel adapter uses it.'));
                        }
                    }
                }),

            $this->check('uplink_redundancy', __('Switch without uplink redundancy'), 'warn', 3,
                __('Give every virtual switch that carries traffic at least two physical uplinks, ideally on different physical switches / NIC cards.'), [],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        $net = $h->data['network'] ?? [];
                        $used = collect($net['portgroups'] ?? [])->pluck('vswitch')->flip();
                        $single = collect($net['vswitches'] ?? [])->filter(fn ($s) => count($s['uplinks']) === 1 && $used->has($s['name']))->pluck('name')
                            ->merge(collect($net['dvs'] ?? [])->filter(fn ($s) => count($s['uplinks']) === 1)->pluck('dvs_name'));
                        if ($single->isNotEmpty()) {
                            $r->obj($h, __('Single uplink: :list.', ['list' => $single->join(', ')]));
                        }
                    }
                }),

            $this->check('pnic_down', __('Uplink NIC down'), 'crit', 4,
                __('An uplink in use has no link: check cable, transceiver and physical switch port. Redundancy is lost until it is back.'), [],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        $net = $h->data['network'] ?? [];
                        $uplinks = collect($net['vswitches'] ?? [])->flatMap(fn ($s) => $s['uplinks'])->merge(collect($net['dvs'] ?? [])->flatMap(fn ($s) => $s['uplinks']))->flip();
                        $down = collect($net['pnics'] ?? [])->filter(fn ($n) => ! $n['speed_mb'] && $uplinks->has($n['device']))->pluck('device');
                        if ($down->isNotEmpty()) {
                            $r->obj($h, __('No link on :list.', ['list' => $down->join(', ')]));
                        }
                    }
                }),

            $this->check('pnic_speed', __('Uplinks with different speeds'), 'info', 0,
                __('Uplinks of the same switch should run at the same speed; a slower link can become the bottleneck after a failover.'), [],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        $net = $h->data['network'] ?? [];
                        $speed = collect($net['pnics'] ?? [])->pluck('speed_mb', 'device');
                        $bad = collect($net['vswitches'] ?? [])->map(fn ($s) => ['name' => $s['name'], 'up' => $s['uplinks']])
                            ->merge(collect($net['dvs'] ?? [])->map(fn ($s) => ['name' => $s['dvs_name'], 'up' => $s['uplinks']]))
                            ->filter(fn ($s) => collect($s['up'])->map(fn ($u) => $speed[$u] ?? null)->filter()->unique()->count() > 1)
                            ->map(fn ($s) => $s['name'].' ('.collect($s['up'])->map(fn ($u) => $u.' '.(($speed[$u] ?? 0) >= 1000 ? (($speed[$u] / 1000).'G') : (($speed[$u] ?? 0).'M')))->join(', ').')');
                        if ($bad->isNotEmpty()) {
                            $r->obj($h, $bad->join('; '));
                        }
                    }
                }),

            $this->check('vmk_mtu', __('MTU mismatch'), 'crit', 3,
                __('A VMkernel adapter MTU larger than its switch MTU drops jumbo frames (vMotion, iSCSI, NFS, vSAN). Set the same MTU end-to-end: vmk, switch and physical network.'), [],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    $byPg = [];   // port group → mtu → host names (for consistency across hosts)
                    foreach ($hosts as $h) {
                        $net = $h->data['network'] ?? [];
                        $pgSwitch = collect($net['portgroups'] ?? [])->pluck('vswitch', 'name');
                        $swMtu = collect($net['vswitches'] ?? [])->pluck('mtu', 'name');
                        $dvsMtu = collect($net['dvs'] ?? [])->pluck('mtu', 'dvs_uuid');
                        $bad = [];
                        foreach ($net['vmks'] ?? [] as $k) {
                            $sw = $k['dvs_uuid'] ? ($dvsMtu[$k['dvs_uuid']] ?? null) : ($swMtu[$pgSwitch[$k['portgroup']] ?? ''] ?? null);
                            if ($sw && $k['mtu'] > $sw) {
                                $bad[] = __(':vmk MTU :m > switch MTU :s', ['vmk' => $k['device'], 'm' => $k['mtu'], 's' => $sw]);
                            }
                            if ($k['portgroup']) {
                                $byPg[$h->cluster ?? ''][$k['portgroup']][$k['mtu']][] = $h->name;
                            }
                        }
                        if ($bad) {
                            $r->obj($h, implode('; ', $bad).'.');
                        }
                    }
                    foreach ($byPg as $cluster => $pgs) {
                        foreach ($pgs as $pg => $mtus) {
                            if ($cluster !== '' && count($mtus) > 1) {
                                $r->add('cluster', $cluster, null, __('VMkernel port group ":pg" has different MTUs: :list.', ['pg' => $pg,
                                    'list' => collect($mtus)->map(fn ($hs, $m) => $m.' ('.self::list($hs, 3).')')->join('; ')]), 'warn');
                            }
                        }
                    }
                }),
        ];
    }
}

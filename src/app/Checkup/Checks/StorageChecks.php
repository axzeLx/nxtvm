<?php

namespace App\Checkup\Checks;

use App\Checkup\CheckSet;
use App\Checkup\Context;
use App\Checkup\Result;
use App\Support\Fmt;

class StorageChecks extends CheckSet
{
    public function category(): string
    {
        return 'storage';
    }

    public function checks(): array
    {
        $shared = fn ($ds) => ! ($ds->data['vmfs']['local'] ?? false) && ($ds->data['multiple_host_access'] ?? true);

        return [
            $this->check('ds_free', __('Datastores running out of space'), 'crit', 5,
                __('Free up space (old snapshots, orphaned files, powered-off VMs), move VMs with Storage vMotion or grow the datastore. A full datastore stops the VMs that need to write.'),
                ['warn_pct' => [20, __('Warning below free (%)')], 'crit_pct' => [10, __('Critical below free (%)')]],
                function (Context $c, array $p, Result $r) {
                    $ds = $c->datastores()->where('capacity_bytes', '>', 0);
                    $r->total($ds->count());
                    foreach ($ds as $d) {
                        $free = 100 * $d->free_bytes / $d->capacity_bytes;
                        if ($free < $p['warn_pct']) {
                            $r->obj($d, __(':pct % free (:free of :cap).', ['pct' => round($free, 1), 'free' => Fmt::bytes($d->free_bytes), 'cap' => Fmt::bytes($d->capacity_bytes)]),
                                $free < $p['crit_pct'] ? 'crit' : 'warn');
                        }
                    }
                }),

            $this->check('ds_overprovisioned', __('Datastore overcommit'), 'warn', 2,
                __('The disks provisioned on the datastore (thin disks at full size) exceed its capacity. If the VMs fill their thin disks the datastore runs full and the VMs that need to write stop; monitor growth, set alarms or move VMs.'),
                ['info_pct' => [100, __('Report provisioned above (%)')], 'warn_pct' => [150, __('Warning provisioned (%)')], 'crit_pct' => [200, __('Critical provisioned (%)')]],
                function (Context $c, array $p, Result $r) {
                    $ds = $c->datastores()->where('capacity_bytes', '>', 0);
                    $r->total($ds->count());
                    $max = null;
                    foreach ($ds as $d) {
                        $pct = 100 * $d->provisioned_bytes / $d->capacity_bytes;
                        if ($max === null || $pct > $max[0]) {
                            $max = [$pct, $d->name];
                        }
                        if ($pct > $p['info_pct']) {
                            $r->obj($d, __('Overcommitted: provisioned :pct % (:prov on :cap, :over over capacity).', [
                                'pct' => round($pct), 'prov' => Fmt::bytes($d->provisioned_bytes), 'cap' => Fmt::bytes($d->capacity_bytes),
                                'over' => Fmt::bytes($d->provisioned_bytes - $d->capacity_bytes),
                            ]), match (true) { $pct >= $p['crit_pct'] => 'crit', $pct >= $p['warn_pct'] => 'warn', default => 'info' });
                        }
                    }
                    if ($max !== null && ! $r->findings) {
                        $r->note(__('No overcommitted datastore (highest: :pct % on :ds).', ['pct' => round($max[0]), 'ds' => $max[1]]));
                    }
                }),

            $this->check('ds_access', __('Datastore not accessible'), 'crit', 5,
                __('Check the storage paths, LUN masking / zoning or NFS export; inaccessible datastores leave their VMs without disks.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->datastores()->count());
                    foreach ($c->datastores() as $d) {
                        $bad = collect($d->data['hosts'] ?? [])->filter(fn ($h) => $h['mounted'] && $h['accessible'] === false)
                            ->map(fn ($h) => $c->host($h['host'])?->name ?? $h['host']);
                        if (! $d->accessible) {
                            $r->obj($d, __('Datastore is not accessible.'));
                        } elseif ($bad->isNotEmpty()) {
                            $r->obj($d, __('Not accessible from :hosts.', ['hosts' => self::list($bad)]));
                        } elseif (($d->data['maintenance'] ?? 'normal') !== 'normal' && ($d->data['maintenance'] ?? null)) {
                            $r->obj($d, __('Datastore maintenance mode: :m.', ['m' => $d->data['maintenance']]), 'warn');
                        }
                    }
                }),

            $this->check('ds_vmfs5', __('VMFS5 datastores'), 'warn', 1,
                __('VMFS5 is deprecated; create a VMFS6 datastore and move the VMs (there is no in-place upgrade).'), [],
                function (Context $c, array $p, Result $r) {
                    $vmfs = $c->datastores()->filter(fn ($d) => ! empty($d->data['vmfs']['version']));
                    $r->total($vmfs->count());
                    foreach ($vmfs as $d) {
                        if (str_starts_with((string) $d->data['vmfs']['version'], '5')) {
                            $r->obj($d, __('VMFS :v.', ['v' => $d->data['vmfs']['version']]));
                        }
                    }
                }),

            $this->check('ds_visibility', __('Shared datastore not on all cluster hosts'), 'warn', 3,
                __('Present the datastore to every host of the cluster; otherwise HA restarts, DRS and vMotion fail for VMs on it.'), [],
                function (Context $c, array $p, Result $r) use ($shared) {
                    $ds = $c->datastores()->filter($shared);
                    $r->total($ds->count());
                    $clusters = $c->clusters()->mapWithKeys(fn ($cl) => [$cl->name => $c->clusterHosts($cl)->pluck('name', 'moid')]);
                    foreach ($ds as $d) {
                        $mounted = collect($d->data['hosts'] ?? [])->filter(fn ($h) => $h['mounted'] !== false)->pluck('host')->flip();
                        $missing = [];
                        foreach ($clusters as $cl => $hosts) {
                            $on = $hosts->keys()->filter(fn ($m) => $mounted->has($m));
                            if ($on->isNotEmpty() && $on->count() < $hosts->count()) {
                                $missing[] = __(':hosts (cluster :cl)', ['hosts' => self::list($hosts->except($on->all())->values()), 'cl' => $cl]);
                            }
                        }
                        if ($missing) {
                            $r->obj($d, __('Not mounted on :list.', ['list' => implode('; ', $missing)]));
                        }
                    }
                }),

            $this->check('vm_local_ds', __('VMs on local datastores in HA clusters'), 'warn', 2,
                __('VMs on local disks cannot be restarted by HA or moved by DRS; move them to shared storage.'), [],
                function (Context $c, array $p, Result $r) {
                    $haHosts = $c->clusters()->filter(fn ($cl) => $cl->ha_enabled && $cl->num_hosts > 1)
                        ->flatMap(fn ($cl) => $c->clusterHosts($cl)->pluck('moid'))->flip();
                    $vms = $c->poweredOnVms()->filter(fn ($v) => $haHosts->has($v->host_moid));
                    $r->total($vms->count());
                    foreach ($vms as $v) {
                        $local = collect($v->data['disks'] ?? [])->map(fn ($d) => $c->datastore($d['datastore']))
                            ->filter(fn ($ds) => $ds && ($ds->data['vmfs']['local'] ?? false))->pluck('name')->unique();
                        if ($local->isNotEmpty()) {
                            $r->obj($v, __('Disks on local datastore :ds.', ['ds' => $local->join(', ')]));
                        }
                    }
                }),

            $this->check('storage_paths', __('Storage path redundancy'), 'crit', 4,
                __('Repair dead paths (cabling, switch ports, zoning, array ports) and make sure every shared LUN has at least two paths.'), [],
                function (Context $c, array $p, Result $r) {
                    $hosts = $c->hosts()->where('connection_state', 'connected');
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        $paths = collect($h->data['storage_paths'] ?? [])->reject(fn ($l) => $l['local'] ?? false);
                        $dead = $paths->filter(fn ($l) => ($l['dead'] ?? 0) > 0);
                        $single = $paths->filter(fn ($l) => ($l['paths'] ?? 0) === 1);
                        if ($dead->isNotEmpty()) {
                            $r->obj($h, __(':n dead path(s) on :luns.', ['n' => $dead->sum('dead'), 'luns' => self::list($dead->pluck('canonical'), 3)]), 'crit');
                        }
                        if ($single->isNotEmpty()) {
                            $r->obj($h, __(':n shared LUN(s) with a single path: :luns.', ['n' => $single->count(), 'luns' => self::list($single->pluck('canonical'), 3)]), 'warn');
                        }
                    }
                }),
        ];
    }
}

<?php

namespace App\Checkup\Checks;

use App\Checkup\CheckSet;
use App\Checkup\Context;
use App\Checkup\Result;
use App\Support\Fmt;

class ClusterChecks extends CheckSet
{
    public function category(): string
    {
        return 'clusters';
    }

    public function checks(): array
    {
        // clusters with more than one host — HA / DRS only make sense there
        $multi = fn (Context $c) => $c->clusters()->filter(fn ($cl) => $cl->num_hosts > 1)->values();

        return [
            $this->check('ha_disabled', __('vSphere HA disabled'), 'crit', 5,
                __('Enable vSphere HA so VMs restart on the remaining hosts after a host failure.'), [],
                function (Context $c, array $p, Result $r) use ($multi) {
                    $r->total($multi($c)->count());
                    foreach ($multi($c)->reject->ha_enabled as $cl) {
                        $r->obj($cl, __('HA is disabled (:n hosts).', ['n' => $cl->num_hosts]));
                    }
                }),

            $this->check('ha_settings', __('HA protection settings'), 'warn', 3,
                __('Keep admission control enabled (reserve the capacity of at least one host) and host monitoring on, otherwise HA may not be able to restart all VMs after a failure.'), [],
                function (Context $c, array $p, Result $r) use ($multi) {
                    $ha = $multi($c)->filter->ha_enabled;
                    $r->total($ha->count());
                    foreach ($ha as $cl) {
                        $h = $cl->data['ha'] ?? [];
                        $issues = array_filter([
                            ($h['admission_control'] ?? true) ? null : __('admission control disabled'),
                            ($h['host_monitoring'] ?? 'enabled') === 'enabled' ? null : __('host monitoring disabled'),
                        ]);
                        if ($issues) {
                            $r->obj($cl, ucfirst(implode(', ', $issues)).'.');
                        }
                    }
                }),

            $this->check('ha_vm_monitoring', __('HA VM monitoring off'), 'info', 0,
                __('VM monitoring restarts a VM whose guest stopped sending VMware Tools heartbeats (hung OS). Consider enabling it.'), [],
                function (Context $c, array $p, Result $r) use ($multi) {
                    $ha = $multi($c)->filter->ha_enabled;
                    $r->total($ha->count());
                    foreach ($ha as $cl) {
                        if (($cl->data['ha']['vm_monitoring'] ?? null) === 'vmMonitoringDisabled') {
                            $r->obj($cl, __('VM monitoring is disabled.'));
                        }
                    }
                }),

            $this->check('drs', __('DRS disabled or manual'), 'warn', 3,
                __('Enable DRS in fully automated mode so load is balanced and maintenance mode evacuates hosts automatically. Disabling DRS also deletes resource pools and rules.'), [],
                function (Context $c, array $p, Result $r) use ($multi) {
                    $r->total($multi($c)->count());
                    foreach ($multi($c) as $cl) {
                        $behavior = $cl->data['drs']['behavior'] ?? null;
                        if (! $cl->drs_enabled) {
                            $r->obj($cl, __('DRS is disabled.'));
                        } elseif ($behavior !== 'fullyAutomated') {
                            $r->obj($cl, __('DRS automation level: :b.', ['b' => $behavior]), 'info');
                        }
                    }
                }),

            $this->check('single_host', __('Single-host clusters'), 'warn', 2,
                __('A cluster with one host has no failover capacity; add a host or treat its VMs as unprotected.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->clusters()->count());
                    foreach ($c->clusters()->where('num_hosts', 1) as $cl) {
                        $r->obj($cl, __('Only one host.'));
                    }
                }),

            $this->check('n_plus_1', __('Failover capacity (N+1)'), 'crit', 5,
                __('If the largest host fails, the remaining hosts must hold the memory of all running VMs. Add capacity, or reduce / move workloads.'),
                ['warn_pct' => [90, __('Warning when remaining memory is used above (%)')]],
                function (Context $c, array $p, Result $r) use ($multi) {
                    $r->total($multi($c)->count());
                    foreach ($multi($c) as $cl) {
                        $hosts = $c->clusterHosts($cl)->reject->maintenance;
                        if ($hosts->count() < 2) {
                            continue;
                        }
                        $capacity = $hosts->sum('mem_bytes') - $hosts->max('mem_bytes');
                        $used = $hosts->sum(fn ($h) => (int) $h->mem_usage_mb * 1048576);
                        $pct = Fmt::pct($used, $capacity);
                        if ($pct !== null && $pct >= $p['warn_pct']) {
                            $r->obj($cl, __('Memory in use :used would need :pct % of the :cap left after losing the largest host.', [
                                'used' => Fmt::bytes($used), 'pct' => round($pct), 'cap' => Fmt::bytes($capacity),
                            ]), $pct >= 100 ? 'crit' : 'warn');
                        }
                    }
                }),

            $this->check('evc', __('Mixed CPUs without EVC'), 'warn', 2,
                __('Enable EVC at the level of the oldest CPU generation so vMotion keeps working between the different hosts.'), [],
                function (Context $c, array $p, Result $r) use ($multi) {
                    $r->total($multi($c)->count());
                    foreach ($multi($c) as $cl) {
                        $models = $c->clusterHosts($cl)->pluck('cpu_model')->filter()->map(fn ($m) => Fmt::cpuShort($m))->unique();
                        if ($models->count() > 1 && empty($cl->data['evc_mode'])) {
                            $r->obj($cl, __('CPU models :list, EVC disabled.', ['list' => $models->join(', ')]));
                        }
                    }
                }),

            $this->check('drs_rules', __('DRS rule problems'), 'warn', 1,
                __('Remove or re-enable disabled rules and update rules that reference VMs which no longer exist; stale rules are easy to misread during incidents.'), [],
                function (Context $c, array $p, Result $r) {
                    $vms = $c->vms()->pluck('moid')->flip();
                    $rules = 0;
                    foreach ($c->clusters() as $cl) {
                        $issues = [];
                        foreach ($cl->data['rules'] ?? [] as $rule) {
                            $rules++;
                            if (! $rule['enabled']) {
                                $issues[] = __('":n" is disabled', ['n' => $rule['name']]);
                            } elseif ($rule['vms'] && collect($rule['vms'])->reject(fn ($m) => $vms->has($m))->isNotEmpty()) {
                                $issues[] = __('":n" references missing VMs', ['n' => $rule['name']]);
                            }
                        }
                        if ($issues) {
                            $r->obj($cl, ucfirst(implode('; ', $issues)).'.');
                        }
                    }
                    $r->total($rules);
                }),

            $this->check('vcpu_ratio', __('vCPU overcommitment'), 'warn', 2,
                __('A high vCPU to physical core ratio leads to CPU ready time. Right-size VMs with many vCPUs or add hosts.'),
                ['warn' => [4.0, __('Warning ratio (vCPU : core)')], 'crit' => [8.0, __('Critical ratio')]],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->clusters()->count());
                    foreach ($c->clusters() as $cl) {
                        $cores = $c->clusterHosts($cl)->reject->maintenance->sum('cpu_cores');
                        $vcpu = $c->clusterVms($cl)->sum('vcpu');
                        if ($cores > 0 && ($ratio = $vcpu / $cores) >= $p['warn']) {
                            $r->obj($cl, __(':v vCPU on :c cores (:ratio : 1).', ['v' => $vcpu, 'c' => $cores, 'ratio' => round($ratio, 1)]), $ratio >= $p['crit'] ? 'crit' : 'warn');
                        }
                    }
                }),

            $this->check('mem_overcommit', __('Memory overcommitment'), 'warn', 3,
                __('Configured VM memory well above physical memory risks ballooning and swapping under load. Right-size VMs or add memory / hosts.'),
                ['warn' => [1.2, __('Warning ratio (configured : physical)')], 'crit' => [1.5, __('Critical ratio')]],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->clusters()->count());
                    foreach ($c->clusters() as $cl) {
                        $phys = $c->clusterHosts($cl)->reject->maintenance->sum('mem_bytes');
                        $conf = $c->clusterVms($cl)->sum(fn ($v) => (int) $v->mem_mb * 1048576);
                        if ($phys > 0 && ($ratio = $conf / $phys) >= $p['warn']) {
                            $r->obj($cl, __(':conf configured on :phys physical (:ratio : 1).', ['conf' => Fmt::bytes($conf), 'phys' => Fmt::bytes($phys), 'ratio' => round($ratio, 2)]), $ratio >= $p['crit'] ? 'crit' : 'warn');
                        }
                    }
                }),
        ];
    }
}

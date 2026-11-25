<?php

namespace App\Checkup\Checks;

use App\Checkup\CheckSet;
use App\Checkup\Context;
use App\Checkup\Result;
use App\Support\Fmt;

class VmChecks extends CheckSet
{
    public function category(): string
    {
        return 'vms';
    }

    public function checks(): array
    {
        return [
            $this->check('vm_inaccessible', __('Inaccessible or orphaned VMs'), 'crit', 4,
                __('Find out why the VM is inaccessible / orphaned (missing datastore, deleted files, host problem); re-register or remove it from the inventory.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->vms()->count());
                    foreach ($c->vms() as $v) {
                        $s = $v->data['connection_state'] ?? 'connected';
                        if ($s !== 'connected') {
                            $r->obj($v, __('Connection state: :s.', ['s' => $s]));
                        }
                    }
                }),

            $this->check('consolidation', __('Disk consolidation needed'), 'crit', 4,
                __('Run Snapshots → Consolidate. Unconsolidated delta disks keep growing and slow the VM down.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->vms()->count());
                    foreach ($c->vms() as $v) {
                        if ($v->data['consolidation_needed'] ?? false) {
                            $r->obj($v, __('Virtual disks need consolidation.'));
                        }
                    }
                }),

            $this->check('tools_running', __('VMware Tools not running'), 'warn', 3,
                __('Install / start VMware Tools (open-vm-tools on Linux): needed for clean shutdowns, quiesced backups, HA VM monitoring and guest information.'), [],
                function (Context $c, array $p, Result $r) {
                    $vms = $c->poweredOnVms();
                    $r->total($vms->count());
                    foreach ($vms as $v) {
                        if ($v->tools_status === 'guestToolsNotInstalled') {
                            $r->obj($v, __('VMware Tools not installed.'));
                        } elseif ($v->tools_running !== 'guestToolsRunning') {
                            $r->obj($v, __('VMware Tools installed but not running.'));
                        }
                    }
                }),

            $this->check('tools_outdated', __('VMware Tools outdated'), 'warn', 1,
                __('Upgrade VMware Tools (or the open-vm-tools package) to get current drivers and fixes.'), [],
                function (Context $c, array $p, Result $r) {
                    $vms = $c->poweredOnVms()->whereNotIn('tools_status', ['guestToolsNotInstalled', null]);
                    $r->total($vms->count());
                    foreach ($vms as $v) {
                        if (in_array($v->tools_status, ['guestToolsSupportedOld', 'guestToolsNeedUpgrade', 'guestToolsTooOld', 'guestToolsBlacklisted'], true)) {
                            $r->obj($v, __('Tools :ver (:s).', ['ver' => $v->data['tools_version'] ?? '?', 's' => Fmt::state($v->tools_status)]),
                                in_array($v->tools_status, ['guestToolsTooOld', 'guestToolsBlacklisted'], true) ? 'crit' : 'warn');
                        }
                    }
                }),

            $this->check('hw_version', __('Old virtual hardware'), 'warn', 1,
                __('Upgrade the virtual hardware (compatibility) during a maintenance window, after upgrading VMware Tools. Old versions miss features and security mitigations.'),
                ['min' => [14, __('Minimum hardware version (vmx-N)')]],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->vms()->count());
                    foreach ($c->vms() as $v) {
                        $n = (int) preg_replace('/\D/', '', (string) $v->hw_version);
                        if ($n && $n < $p['min']) {
                            $r->obj($v, __('Hardware version :v.', ['v' => $v->hw_version]));
                        }
                    }
                }),

            $this->check('cdrom', __('CD/DVD drive connected'), 'warn', 1,
                __('Disconnect the ISO / host device after use; connected media blocks vMotion, DRS and maintenance mode.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->vms()->count());
                    foreach ($c->vms() as $v) {
                        $cd = collect($v->data['cdroms'] ?? [])->filter(fn ($d) => $d['connected'] || ($d['start_connected'] && $d['iso']));
                        if ($cd->isNotEmpty()) {
                            $r->obj($v, $cd->map(fn ($d) => $d['label'].($d['iso'] ? ': '.$d['iso'] : ''))->join('; '));
                        }
                    }
                }),

            $this->check('limits', __('CPU or memory limits set'), 'warn', 2,
                __('Remove CPU / memory limits unless intended: a memory limit below the configured memory forces ballooning and swapping even when the host has free memory.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->vms()->count());
                    foreach ($c->vms() as $v) {
                        $l = [];
                        if (($v->data['cpu_limit'] ?? -1) >= 0) {
                            $l[] = __('CPU limit :n MHz', ['n' => $v->data['cpu_limit']]);
                        }
                        if (($v->data['mem_limit'] ?? -1) >= 0) {
                            $l[] = __('memory limit :n MB (configured :m MB)', ['n' => $v->data['mem_limit'], 'm' => $v->mem_mb]);
                        }
                        if ($l) {
                            $r->obj($v, ucfirst(implode(', ', $l)).'.');
                        }
                    }
                }),

            $this->check('oversized_vcpu', __('More vCPUs than host cores'), 'warn', 2,
                __('A VM should not have more vCPUs than the physical cores of its host; reduce the vCPU count.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->poweredOnVms()->count());
                    foreach ($c->poweredOnVms() as $v) {
                        $h = $c->host($v->host_moid);
                        if ($h && $h->cpu_cores && $v->vcpu > $h->cpu_cores) {
                            $r->obj($v, __(':v vCPU on a host with :c cores.', ['v' => $v->vcpu, 'c' => $h->cpu_cores]));
                        }
                    }
                }),

            $this->check('hot_add', __('CPU hot add enabled'), 'info', 0,
                __('CPU hot add disables vNUMA; disable it on large VMs (more than 8 vCPUs) unless it is really used.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->vms()->count());
                    foreach ($c->vms() as $v) {
                        if (($v->data['cpu_hot_add'] ?? false) && $v->vcpu > 8) {
                            $r->obj($v, __('CPU hot add on a VM with :n vCPUs.', ['n' => $v->vcpu]));
                        }
                    }
                }),

            $this->check('e1000', __('Emulated network adapter (E1000)'), 'warn', 1,
                __('Replace E1000 / E1000e adapters with VMXNET3 for lower CPU overhead and better throughput.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->vms()->count());
                    foreach ($c->vms() as $v) {
                        $nics = collect($v->data['nics'] ?? [])->filter(fn ($n) => str_starts_with(strtolower((string) $n['type']), 'e1000'));
                        if ($nics->isNotEmpty()) {
                            $r->obj($v, $nics->map(fn ($n) => $n['label'].' ('.$n['type'].')')->join(', '));
                        }
                    }
                }),

            $this->check('nic_disconnected', __('Network adapter not connected'), 'warn', 1,
                __('Connect the adapter (and enable "Connect at power on") or remove it if it is not needed.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->poweredOnVms()->count());
                    foreach ($c->poweredOnVms() as $v) {
                        $nics = collect($v->data['nics'] ?? [])->filter(fn ($n) => $n['connected'] === false || $n['start_connected'] === false);
                        if ($nics->isNotEmpty()) {
                            $r->obj($v, $nics->map(fn ($n) => $n['label'].' → '.($n['network'] ?? '?').' ('.(! $n['connected'] ? __('disconnected') : __('not connected at power on')).')')->join('; '));
                        }
                    }
                }),

            $this->check('guest_os_mismatch', __('Guest OS type mismatch'), 'info', 0,
                __('Set the configured guest OS to the running operating system; it drives default devices, tools and support statements.'), [],
                function (Context $c, array $p, Result $r) {
                    $vms = $c->poweredOnVms()->filter(fn ($v) => $v->tools_running === 'guestToolsRunning');
                    $r->total($vms->count());
                    $norm = fn ($s) => strtolower(preg_replace('/\s*\((32|64)-bit\)|\s+/', '', (string) $s));
                    foreach ($vms as $v) {
                        $cfg = $v->data['guest_os_config'] ?? null;
                        if ($cfg && $v->guest_os && $norm($cfg) !== $norm($v->guest_os)) {
                            $r->obj($v, __('Configured ":cfg", running ":run".', ['cfg' => $cfg, 'run' => $v->guest_os]));
                        }
                    }
                }),

            $this->check('guest_disk', __('Guest file systems almost full'), 'warn', 3,
                __('Extend or clean up the file system inside the guest before applications fail.'),
                ['warn_pct' => [90, __('Warning used (%)')], 'crit_pct' => [95, __('Critical used (%)')]],
                function (Context $c, array $p, Result $r) {
                    $vms = $c->poweredOnVms()->filter(fn ($v) => ! empty($v->data['guest_disks']));
                    $r->total($vms->count());
                    foreach ($vms as $v) {
                        $full = collect($v->data['guest_disks'])->filter(fn ($d) => $d['capacity'] > 0)
                            ->map(fn ($d) => $d + ['pct' => 100 * ($d['capacity'] - $d['free']) / $d['capacity']])
                            ->filter(fn ($d) => $d['pct'] >= $p['warn_pct'])->sortByDesc('pct');
                        if ($full->isNotEmpty()) {
                            $r->obj($v, $full->map(fn ($d) => $d['path'].' '.round($d['pct']).' % ('.Fmt::bytes($d['free']).' '.__('free').')')->join(', '),
                                $full->first()['pct'] >= $p['crit_pct'] ? 'crit' : 'warn');
                        }
                    }
                }),

            $this->check('legacy_devices', __('Unneeded virtual devices'), 'info', 0,
                __('Remove floppy, serial, parallel and USB devices that are not used; they are an attack surface and can block vMotion.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->vms()->count());
                    foreach ($c->vms() as $v) {
                        if (! empty($v->data['other_devices'])) {
                            $r->obj($v, collect($v->data['other_devices'])->map(fn ($d) => $d['label'])->join(', '));
                        }
                    }
                }),

            $this->check('powered_off', __('Powered-off VMs using storage'), 'info', 0,
                __('Review VMs that stay powered off: archive or delete them to free datastore space.'),
                ['min_gb' => [50, __('Report from (GB used)')]],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->vms()->count());
                    foreach ($c->vms()->where('power_state', 'poweredOff') as $v) {
                        if ($v->used_bytes >= $p['min_gb'] * 1073741824) {
                            $r->obj($v, __('Powered off, uses :b.', ['b' => Fmt::bytes($v->used_bytes)]));
                        }
                    }
                }),
        ];
    }
}

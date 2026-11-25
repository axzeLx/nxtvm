<?php

namespace App\Checkup\Checks;

use App\Checkup\CheckSet;
use App\Checkup\Context;
use App\Checkup\Result;
use Carbon\CarbonImmutable;

class HostChecks extends CheckSet
{
    /** End of general support per vSphere major.minor (editable in the settings) */
    public const EOL = '6.5:2022-10-15, 6.7:2022-10-15, 7.0:2025-10-02, 8.0:2027-10-11';

    public function category(): string
    {
        return 'hosts';
    }

    /** "7.0.3" → end of general support date, from "7.0:2025-10-02, …" */
    public static function eol(?string $version, string $table): ?CarbonImmutable
    {
        if (! $version || ! preg_match('/^(\d+\.\d+)/', $version, $m)) {
            return null;
        }
        foreach (explode(',', $table) as $pair) {
            [$v, $d] = array_map('trim', explode(':', $pair, 2) + [1 => '']);
            if ($v === $m[1] && strtotime($d)) {
                return CarbonImmutable::parse($d);
            }
        }

        return null;
    }

    public function checks(): array
    {
        $connected = fn (Context $c) => $c->hosts()->where('connection_state', 'connected')->values();

        return [
            $this->check('host_connection', __('Hosts not connected'), 'crit', 5,
                __('Reconnect the host or find out why it does not respond (management network, hostd / vpxa services, hardware). Disconnected hosts are not managed and their VMs are not protected.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->hosts()->count());
                    foreach ($c->hosts() as $h) {
                        if ($h->connection_state !== 'connected') {
                            $r->obj($h, __('Connection state: :s.', ['s' => $h->connection_state ?? '?']));
                        }
                    }
                }),

            $this->check('host_status', __('Host health status'), 'warn', 3,
                __('Open the host in vSphere Client and resolve the triggered alarms / hardware health issues that turn its status yellow or red.'), [],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        if (in_array($h->overall_status, ['red', 'yellow'], true)) {
                            $r->obj($h, __('Overall status is :s.', ['s' => $h->overall_status]), $h->overall_status === 'red' ? 'crit' : 'warn');
                        }
                    }
                }),

            $this->check('host_uptime', __('Hosts not rebooted for a long time'), 'warn', 3,
                __('A long uptime usually means patches and firmware updates were not installed. Plan a patch cycle (vLCM) with rolling maintenance mode.'),
                ['warn_days' => [180, __('Warning (days)')], 'crit_days' => [365, __('Critical (days)')]],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        $days = isset($h->data['uptime_s']) ? self::days($h->data['uptime_s']) : ($h->boot_time ? (int) $h->boot_time->diffInDays(now()) : null);
                        if ($days !== null && $days >= $p['warn_days']) {
                            $r->obj($h, __('Up for :n days (booted :date).', ['n' => $days, 'date' => $h->boot_time?->toDateString() ?? '?']), $days >= $p['crit_days'] ? 'crit' : 'warn');
                        }
                    }
                }),

            $this->check('host_maintenance', __('Hosts in maintenance mode'), 'warn', 2,
                __('Finish the maintenance and exit maintenance mode; a host left in maintenance reduces the cluster capacity and HA failover headroom.'), [],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    foreach ($hosts->where('maintenance', true) as $h) {
                        $r->obj($h, __('In maintenance mode.'));
                    }
                }),

            $this->check('host_eol', __('ESXi version out of support'), 'warn', 3,
                __('Plan the upgrade to a supported ESXi release. Versions past end of general support get no security patches.'),
                ['eol' => [self::EOL, __('End of general support (version:date, …)')], 'notice_days' => [180, __('Notice before end (days)')]],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->hosts()->count());
                    foreach ($c->hosts() as $h) {
                        $eol = self::eol($h->version, $p['eol']);
                        if (! $eol) {
                            continue;
                        }
                        if ($eol->isPast()) {
                            $r->obj($h, __('ESXi :v reached end of general support on :date.', ['v' => $h->version, 'date' => $eol->toDateString()]));
                        } elseif ($eol->lte(now()->addDays($p['notice_days']))) {
                            $r->obj($h, __('ESXi :v reaches end of general support on :date.', ['v' => $h->version, 'date' => $eol->toDateString()]), 'info');
                        }
                    }
                }),

            $this->check('build_mismatch', __('Mixed ESXi builds in a cluster'), 'warn', 2,
                __('Bring all hosts of a cluster to the same ESXi build (vLCM image / baseline) so behaviour, drivers and vMotion compatibility are consistent.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->clusters()->count());
                    foreach ($c->clusters() as $cl) {
                        $builds = $c->clusterHosts($cl)->groupBy(fn ($h) => $h->version.' '.$h->build);
                        if ($builds->count() > 1) {
                            $r->obj($cl, __('Builds: :list.', ['list' => $builds->map(fn ($g, $b) => $b.' ('.$g->count().')')->join(', ')]));
                        }
                    }
                }),

            $this->check('scratch', __('Scratch on a ramdisk'), 'warn', 2,
                __('Configure a persistent scratch location (ScratchConfig.ConfiguredScratchLocation) on a datastore; logs on a ramdisk are lost on reboot and support bundles may fail.'), [],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        $loc = $h->data['advanced']['ScratchConfig.CurrentScratchLocation'] ?? null;
                        if ($loc !== null && ($loc === '' || str_starts_with($loc, '/tmp'))) {
                            $r->obj($h, __('Scratch location: :loc.', ['loc' => $loc ?: __('none')]));
                        }
                    }
                }),

            $this->check('syslog', __('No remote syslog'), 'warn', 2,
                __('Set Syslog.global.logHost to a central syslog / log collector so host logs survive failures and can be audited.'), [],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        if (array_key_exists('Syslog.global.logHost', $h->data['advanced'] ?? []) && trim((string) $h->data['advanced']['Syslog.global.logHost']) === '') {
                            $r->obj($h, __('Syslog.global.logHost is empty.'));
                        }
                    }
                }),

            $this->check('ntp', __('Time synchronisation'), 'warn', 3,
                __('Configure at least two NTP servers, start ntpd with policy "Start and stop with host". Wrong time breaks authentication, certificates and log correlation.'), [],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        $servers = $h->data['ntp']['servers'] ?? [];
                        $svc = self::service($h->data, 'ntpd');
                        if (! $servers) {
                            $r->obj($h, __('No NTP server configured.'));
                        } elseif ($svc && ! $svc['running']) {
                            $r->obj($h, __('NTP servers configured but ntpd is not running.'));
                        } elseif (count($servers) < 2) {
                            $r->obj($h, __('Only one NTP server (:s).', ['s' => $servers[0]]), 'info');
                        }
                    }
                }),

            $this->check('dns', __('DNS servers'), 'warn', 2,
                __('Configure two DNS servers on every host; vCenter, HA and certificates rely on name resolution.'), [],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        $dns = $h->data['network']['dns']['servers'] ?? [];
                        if (! $dns) {
                            $r->obj($h, __('No DNS server configured.'));
                        } elseif (count($dns) < 2) {
                            $r->obj($h, __('Only one DNS server (:s).', ['s' => $dns[0]]), 'info');
                        }
                    }
                }),

            $this->check('cluster_consistency', __('Inconsistent host settings in a cluster'), 'info', 0,
                __('Hosts of a cluster should have the same NTP and DNS servers; use host profiles or a configuration script to keep them consistent.'), [],
                function (Context $c, array $p, Result $r) {
                    $r->total($c->clusters()->count());
                    foreach ($c->clusters() as $cl) {
                        $hosts = $c->clusterHosts($cl);
                        $diff = [];
                        foreach (['NTP' => fn ($h) => $h->data['ntp']['servers'] ?? [], 'DNS' => fn ($h) => $h->data['network']['dns']['servers'] ?? []] as $what => $get) {
                            if ($hosts->map(fn ($h) => implode(',', collect($get($h))->sort()->all()))->unique()->count() > 1) {
                                $diff[] = $what;
                            }
                        }
                        if ($diff) {
                            $r->obj($cl, __(':what servers differ between the hosts.', ['what' => implode(' / ', $diff)]));
                        }
                    }
                }),

            $this->check('power_policy', __('Power policy not "High performance"'), 'info', 0,
                __('For latency sensitive workloads set the host power policy to High performance (and the BIOS power profile to OS controlled / performance).'), [],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    $names = ['dynamic' => 'Balanced', 'low' => 'Low power', 'custom' => 'Custom'];
                    foreach ($hosts as $h) {
                        $pp = $h->data['power_policy'] ?? null;
                        if ($pp && $pp !== 'static') {
                            $r->obj($h, __('Power policy: :p.', ['p' => $names[$pp] ?? $pp]));
                        }
                    }
                }),

            $this->check('hyperthreading', __('Hyperthreading not active'), 'info', 0,
                __('Enable hyperthreading in the BIOS and on the host unless a security policy requires it off (side-channel mitigations).'), [],
                function (Context $c, array $p, Result $r) use ($connected) {
                    $hosts = $connected($c);
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        if (($h->data['hyperthreading'] ?? null) === false) {
                            $r->obj($h, __('Hyperthreading is not active (:t threads on :c cores).', ['t' => $h->cpu_threads, 'c' => $h->cpu_cores]));
                        }
                    }
                }),
        ];
    }
}

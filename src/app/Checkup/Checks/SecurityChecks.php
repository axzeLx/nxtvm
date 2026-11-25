<?php

namespace App\Checkup\Checks;

use App\Checkup\CheckSet;
use App\Checkup\Context;
use App\Checkup\Result;

class SecurityChecks extends CheckSet
{
    public function category(): string
    {
        return 'security';
    }

    public function checks(): array
    {
        return [
            $this->check('cert_expiry', __('Certificates expiring'), 'warn', 4,
                __('Renew the certificate before it expires (vCenter: Certificate Management / VMCA, hosts: Renew Certificate). Expired certificates break host connections and solution logins.'),
                ['warn_days' => [config('nxtvm.cert_warn_days'), __('Warning (days left)')], 'crit_days' => [config('nxtvm.cert_crit_days'), __('Critical (days left)')]],
                function (Context $c, array $p, Result $r) {
                    $certs = $c->certificates()->filter(fn ($x) => $x->valid_to);
                    $r->total($certs->count());
                    foreach ($certs as $cert) {
                        $days = (int) floor(now()->diffInDays($cert->valid_to, false));
                        if ($days > $p['warn_days']) {
                            continue;
                        }
                        $msg = $days < 0
                            ? __(':scope certificate expired :n days ago (:date).', ['scope' => $cert->scope, 'n' => -$days, 'date' => $cert->valid_to->toDateString()])
                            : __(':scope certificate expires in :n days (:date).', ['scope' => $cert->scope, 'n' => $days, 'date' => $cert->valid_to->toDateString()]);
                        $r->add($cert->scope === 'host' ? 'host' : 'certificate', $cert->owner, $cert->owner_moid, $msg, $days <= $p['crit_days'] ? 'crit' : 'warn');
                    }
                }),

            $this->check('ssh_enabled', __('SSH enabled on hosts'), 'warn', 2,
                __('Stop the SSH service and set its policy to "Start and stop manually"; enable it only temporarily for troubleshooting.'), [],
                function (Context $c, array $p, Result $r) {
                    $hosts = $c->hosts()->where('connection_state', 'connected');
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        $s = self::service($h->data, 'TSM-SSH');
                        if ($s && ($s['running'] || $s['policy'] === 'on')) {
                            $r->obj($h, $s['running'] ? __('SSH is running (start policy: :p).', ['p' => $s['policy']]) : __('SSH starts with the host (policy "on").'));
                        }
                    }
                }),

            $this->check('shell_enabled', __('ESXi Shell enabled'), 'warn', 2,
                __('Stop the ESXi Shell service and set its policy to manual. Use it only temporarily, together with a shell timeout.'), [],
                function (Context $c, array $p, Result $r) {
                    $hosts = $c->hosts()->where('connection_state', 'connected');
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        $s = self::service($h->data, 'TSM');
                        if ($s && ($s['running'] || $s['policy'] === 'on')) {
                            $r->obj($h, $s['running'] ? __('ESXi Shell is running (start policy: :p).', ['p' => $s['policy']]) : __('ESXi Shell starts with the host (policy "on").'));
                        }
                    }
                }),

            $this->check('lockdown', __('Lockdown mode disabled'), 'warn', 1,
                __('Enable normal lockdown mode so hosts are managed only through vCenter; add break-glass accounts to the exception users list.'), [],
                function (Context $c, array $p, Result $r) {
                    $hosts = $c->hosts()->where('connection_state', 'connected');
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        if (($h->data['lockdown'] ?? null) === 'lockdownDisabled') {
                            $r->obj($h, __('Lockdown mode is disabled.'));
                        }
                    }
                }),

            $this->check('account_lockout', __('No account lockout'), 'warn', 2,
                __('Set Security.AccountLockFailures (e.g. 5) and Security.AccountUnlockTime (e.g. 900 s) to slow down password guessing.'), [],
                function (Context $c, array $p, Result $r) {
                    $hosts = $c->hosts()->where('connection_state', 'connected');
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        $v = $h->data['advanced']['Security.AccountLockFailures'] ?? null;
                        if ($v !== null && (int) $v === 0) {
                            $r->obj($h, __('Security.AccountLockFailures is 0 (accounts are never locked).'));
                        }
                    }
                }),

            $this->check('shell_timeout', __('No shell timeout'), 'warn', 1,
                __('Set UserVars.ESXiShellTimeOut and UserVars.ESXiShellInteractiveTimeOut (e.g. 900 s) so forgotten shell / SSH access closes itself.'), [],
                function (Context $c, array $p, Result $r) {
                    $hosts = $c->hosts()->where('connection_state', 'connected');
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        $a = $h->data['advanced'] ?? [];
                        $zero = collect(['UserVars.ESXiShellTimeOut', 'UserVars.ESXiShellInteractiveTimeOut'])
                            ->filter(fn ($k) => array_key_exists($k, $a) && (int) $a[$k] === 0);
                        if ($zero->isNotEmpty()) {
                            $r->obj($h, __(':keys = 0 (no timeout).', ['keys' => $zero->join(', ')]));
                        }
                    }
                }),

            $this->check('shell_warning', __('Shell warning suppressed'), 'info', 0,
                __('UserVars.SuppressShellWarning hides the "SSH / shell is enabled" warning in vSphere Client; set it back to 0 so enabled shells stay visible.'), [],
                function (Context $c, array $p, Result $r) {
                    $hosts = $c->hosts()->where('connection_state', 'connected');
                    $r->total($hosts->count());
                    foreach ($hosts as $h) {
                        if ((int) ($h->data['advanced']['UserVars.SuppressShellWarning'] ?? 0) === 1) {
                            $r->obj($h, __('The shell / SSH warning is suppressed.'));
                        }
                    }
                }),

            $this->check('vswitch_security', __('Permissive vSwitch security policy'), 'warn', 2,
                __('Reject promiscuous mode, MAC address changes and forged transmits on standard switches and port groups unless a workload needs them (e.g. nested ESXi, some load balancers).'), [],
                function (Context $c, array $p, Result $r) {
                    $hosts = $c->hosts()->where('connection_state', 'connected');
                    $r->total($hosts->count());
                    $labels = ['promiscuous' => __('promiscuous'), 'mac_changes' => __('MAC changes'), 'forged_transmits' => __('forged transmits')];
                    foreach ($hosts as $h) {
                        $net = $h->data['network'] ?? [];
                        $bad = [];
                        foreach ($net['vswitches'] ?? [] as $s) {
                            $on = array_keys(array_filter($s['security'] ?? [], fn ($v) => $v === true));
                            if ($on) {
                                $bad[] = $s['name'].': '.collect($on)->map(fn ($k) => $labels[$k] ?? $k)->join(', ');
                            }
                        }
                        foreach ($net['portgroups'] ?? [] as $pg) {
                            $on = array_keys(array_filter($pg['security'] ?? [], fn ($v) => $v === true));
                            if ($on) {
                                $bad[] = __('port group').' '.$pg['name'].': '.collect($on)->map(fn ($k) => $labels[$k] ?? $k)->join(', ');
                            }
                        }
                        if ($bad) {
                            $r->obj($h, __('Accepts :what.', ['what' => implode('; ', $bad)]));
                        }
                    }
                }),
        ];
    }
}

<?php

namespace App\Checkup\Checks;

use App\Checkup\CheckSet;
use App\Checkup\Context;
use App\Checkup\Result;

class AlarmChecks extends CheckSet
{
    private const TYPES = ['HostSystem' => 'host', 'VirtualMachine' => 'vm', 'Datastore' => 'datastore', 'ClusterComputeResource' => 'cluster',
        'Datacenter' => 'datacenter', 'Folder' => 'vcenter', 'Network' => 'network', 'DistributedVirtualPortgroup' => 'network', 'VmwareDistributedVirtualSwitch' => 'dvswitch'];

    public function category(): string
    {
        return 'alarms';
    }

    public function checks(): array
    {
        // objects that can carry alarms: the denominator, so a handful of alarms in a large inventory costs less
        $objects = fn (Context $c) => $c->hosts()->count() + $c->vms()->count() + $c->datastores()->count() + $c->clusters()->count() + 1;
        $add = function (Result $r, $a, string $msg, ?string $sev = null) {
            $type = self::TYPES[$a->entity_type] ?? 'object';
            // alarms on the root folder are vCenter-wide
            $name = $type === 'vcenter' && ! $a->entity ? $r->vcName : ($a->entity ?? $a->entity_type);
            $r->add($type, $name, $type === 'vcenter' ? null : $a->entity_moid, $msg, $sev);
        };

        return [
            $this->check('alarms_active', __('Active alarms'), 'warn', 4,
                __('Investigate and resolve triggered alarms; acknowledge them only when the cause is known and handled.'), [],
                function (Context $c, array $p, Result $r) use ($objects, $add) {
                    $r->total($objects($c));
                    foreach ($c->alarms()->whereIn('status', ['red', 'yellow'])->sortBy(fn ($a) => $a->status === 'red' ? 0 : 1) as $a) {
                        $add($r, $a, $a->alarm.' — '.__('since :date', ['date' => $a->triggered_at?->toDateString() ?? '?']).($a->acknowledged ? ' ('.__('acknowledged').')' : ''),
                            $a->status === 'red' ? 'crit' : 'warn');
                    }
                }, true),

            $this->check('alarms_old', __('Long-standing alarms'), 'info', 0,
                __('Alarms open for a long time are usually ignored; fix the cause or adjust the alarm definition so real problems stay visible.'),
                ['days' => [30, __('Open for more than (days)')]],
                function (Context $c, array $p, Result $r) use ($add) {
                    $r->total($c->alarms()->count());
                    foreach ($c->alarms() as $a) {
                        if ($a->triggered_at && $a->triggered_at->lt(now()->subDays($p['days']))) {
                            $add($r, $a, $a->alarm.' — '.__('open for :n days', ['n' => (int) $a->triggered_at->diffInDays(now())]));
                        }
                    }
                }, true),
        ];
    }
}

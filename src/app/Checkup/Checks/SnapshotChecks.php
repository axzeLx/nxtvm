<?php

namespace App\Checkup\Checks;

use App\Checkup\CheckSet;
use App\Checkup\Context;
use App\Checkup\Result;
use App\Support\Fmt;
use Carbon\CarbonImmutable;

class SnapshotChecks extends CheckSet
{
    public function category(): string
    {
        return 'snapshots';
    }

    public function checks(): array
    {
        $withSnaps = fn (Context $c) => $c->vms()->filter(fn ($v) => $v->snapshot_count > 0);

        return [
            $this->check('snapshot_age', __('Old snapshots'), 'warn', 4,
                __('Snapshots are not backups. Delete them once the change is verified — old delta disks grow, slow down I/O and make consolidation long and risky.'),
                ['warn_days' => [3, __('Warning (days)')], 'crit_days' => [7, __('Critical (days)')]],
                function (Context $c, array $p, Result $r) use ($withSnaps) {
                    $r->total($c->vms()->count());
                    foreach ($withSnaps($c) as $v) {
                        $oldest = collect($v->data['snapshots'] ?? [])->filter(fn ($s) => ! empty($s['created']))->sortBy('created')->first();
                        if (! $oldest) {
                            continue;
                        }
                        $days = (int) CarbonImmutable::parse($oldest['created'])->diffInDays(now());
                        if ($days >= $p['warn_days']) {
                            $r->obj($v, __('Oldest snapshot ":name" is :n days old (:date); :count snapshot(s), :size.', [
                                'name' => $oldest['name'], 'n' => $days, 'date' => substr($oldest['created'], 0, 10),
                                'count' => $v->snapshot_count, 'size' => Fmt::bytes($v->snapshot_bytes),
                            ]), $days >= $p['crit_days'] ? 'crit' : 'warn');
                        }
                    }
                }),

            $this->check('snapshot_size', __('Large snapshots'), 'warn', 3,
                __('Large delta disks can fill the datastore and take long to consolidate; delete or consolidate the snapshot in a quiet period.'),
                ['warn_gb' => [50, __('Warning (GB)')], 'crit_gb' => [200, __('Critical (GB)')]],
                function (Context $c, array $p, Result $r) use ($withSnaps) {
                    $r->total($c->vms()->count());
                    foreach ($withSnaps($c) as $v) {
                        $gb = $v->snapshot_bytes / 1073741824;
                        if ($gb >= $p['warn_gb']) {
                            $r->obj($v, __('Snapshots use :size.', ['size' => Fmt::bytes($v->snapshot_bytes)]), $gb >= $p['crit_gb'] ? 'crit' : 'warn');
                        }
                    }
                }),

            $this->check('snapshot_chain', __('Long snapshot chains'), 'warn', 2,
                __('Every snapshot level adds I/O overhead; keep chains short (VMware recommends at most 2–3, never more than 32).'),
                ['max' => [3, __('Maximum snapshots per VM')]],
                function (Context $c, array $p, Result $r) use ($withSnaps) {
                    $r->total($c->vms()->count());
                    foreach ($withSnaps($c) as $v) {
                        if ($v->snapshot_count > $p['max']) {
                            $r->obj($v, __(':n snapshots.', ['n' => $v->snapshot_count]));
                        }
                    }
                }),

            $this->check('snapshot_memory', __('Snapshots with memory'), 'info', 0,
                __('Memory snapshots store the VM RAM on the datastore and stun the VM while being taken; prefer quiesced snapshots without memory.'), [],
                function (Context $c, array $p, Result $r) use ($withSnaps) {
                    $r->total($c->vms()->count());
                    foreach ($withSnaps($c) as $v) {
                        $mem = collect($v->data['snapshots'] ?? [])->where('memory', true);
                        if ($mem->isNotEmpty()) {
                            $r->obj($v, __('With memory: :list.', ['list' => self::list($mem->pluck('name'))]));
                        }
                    }
                }),
        ];
    }
}

<?php

namespace App\Checkup\Checks;

use App\Checkup\CheckSet;
use App\Checkup\Context;
use App\Checkup\Result;

class LicenseChecks extends CheckSet
{
    public function category(): string
    {
        return 'licensing';
    }

    /** License list (key, totals, expiry) readable? The read-only role only sees assignments. */
    private static function listReadable(Context $c): bool
    {
        return ! collect($c->warnings())->contains(fn ($w) => str_starts_with($w, 'Licenses:'));
    }

    private static function assignments(Context $c): \Illuminate\Support\Collection
    {
        return $c->licenses()->flatMap(fn ($l) => $l->data['assigned'] ?? []);
    }

    public function checks(): array
    {
        return [
            $this->check('license_eval', __('Evaluation or unlicensed hosts'), 'crit', 5,
                __('Assign a permanent license; when the 60-day evaluation ends hosts disconnect from vCenter and VMs can no longer be powered on.'), [],
                function (Context $c, array $p, Result $r) {
                    $assigned = self::assignments($c);
                    if ($assigned->isEmpty()) {
                        $r->na(__('License assignments could not be read.'));

                        return;
                    }
                    $byEntity = $assigned->keyBy('entity');
                    $r->total($c->hosts()->count() + 1);
                    foreach ($c->hosts() as $h) {
                        $a = $byEntity->get($h->moid);
                        if (! $a) {
                            $r->obj($h, __('No license assigned.'), 'warn');
                        } elseif (stripos((string) $a['license_name'], 'evaluation') !== false) {
                            $r->obj($h, __('Running in evaluation mode.'));
                        }
                    }
                    foreach ($assigned as $a) {
                        if (! str_starts_with((string) $a['entity'], 'host-') && stripos((string) $a['license_name'], 'evaluation') !== false) {
                            $r->add('vcenter', $a['name'] ?? $c->vc->name, null, __('Running in evaluation mode.'));
                        }
                    }
                }),

            $this->check('license_expiry', __('Licenses expiring'), 'warn', 4,
                __('Renew or replace term / subscription licenses before they expire.'),
                ['warn_days' => [90, __('Warning (days left)')], 'crit_days' => [30, __('Critical (days left)')]],
                function (Context $c, array $p, Result $r) {
                    if (! self::listReadable($c)) {
                        $r->na(__('The vCenter user has no permission to read the license list.'));

                        return;
                    }
                    $r->total($c->licenses()->count());
                    foreach ($c->licenses()->filter->expires_at as $l) {
                        $days = (int) floor(now()->diffInDays($l->expires_at, false));
                        if ($days <= $p['warn_days']) {
                            $r->obj($l, $days < 0 ? __('Expired on :date.', ['date' => $l->expires_at->toDateString()]) : __('Expires in :n days (:date).', ['n' => $days, 'date' => $l->expires_at->toDateString()]),
                                $days <= $p['crit_days'] ? 'crit' : 'warn');
                        }
                    }
                }),

            $this->check('license_capacity', __('License capacity exceeded'), 'crit', 4,
                __('More units (CPUs / cores / instances) are in use than licensed; buy additional licenses or reduce usage to stay compliant.'), [],
                function (Context $c, array $p, Result $r) {
                    $ls = $c->licenses()->filter(fn ($l) => $l->total !== null);
                    if (! self::listReadable($c) || $ls->isEmpty()) {
                        $r->na(__('The vCenter user has no permission to read the license list.'));

                        return;
                    }
                    $r->total($ls->count());
                    foreach ($ls as $l) {
                        if ($l->total > 0 && $l->used > $l->total) {
                            $r->obj($l, __(':used of :total :unit used.', ['used' => $l->used, 'total' => $l->total, 'unit' => $l->unit]));
                        }
                    }
                }),
        ];
    }
}

<?php

namespace App\Checkup\Checks;

use App\Checkup\CheckSet;
use App\Checkup\Context;
use App\Checkup\Result;
use App\Support\Fmt;

class VCenterChecks extends CheckSet
{
    public function category(): string
    {
        return 'vcenter';
    }

    public function checks(): array
    {
        $vcAdd = fn (Context $c, Result $r, string $msg, ?string $sev = null) => $r->add('vcenter', $c->vc->name, null, $msg, $sev);
        $setting = fn (Context $c, string $k) => $c->vc->inventory['settings'][$k] ?? null;

        return [
            $this->check('data_stale', __('Inventory data out of date'), 'warn', 2,
                __('The checkup works on the last collected inventory. Fix the collection error (Settings → vCenters) so results reflect the current state.'), [],
                function (Context $c, array $p, Result $r) use ($vcAdd) {
                    $r->total(1);
                    $vc = $c->vc;
                    if ($vc->last_error) {
                        $vcAdd($c, $r, __('Last collection failed: :e', ['e' => mb_substr($vc->last_error, 0, 200)]));
                    } elseif ($vc->last_success_at && $vc->last_success_at->lt(now()->subMinutes(2 * $vc->interval_minutes + 30))) {
                        $vcAdd($c, $r, __('Last successful collection :ago.', ['ago' => Fmt::ago($vc->last_success_at)]));
                    }
                }),

            $this->check('vc_eol', __('vCenter version out of support'), 'warn', 3,
                __('Plan the vCenter upgrade; versions past end of general support get no security patches.'),
                ['eol' => [HostChecks::EOL, __('End of general support (version:date, …)')]],
                function (Context $c, array $p, Result $r) use ($vcAdd) {
                    $r->total(1);
                    $v = $c->vc->about['version'] ?? null;
                    $eol = HostChecks::eol($v, $p['eol']);
                    if ($eol && $eol->isPast()) {
                        $vcAdd($c, $r, __('vCenter :v reached end of general support on :date.', ['v' => $v, 'date' => $eol->toDateString()]));
                    }
                }),

            $this->check('smtp', __('No mail server for alarms'), 'warn', 1,
                __('Configure mail.smtp.server and mail.sender (vCenter → Configure → General → Mail) so alarm actions can send e-mail.'), [],
                function (Context $c, array $p, Result $r) use ($vcAdd, $setting) {
                    if (! is_array($c->vc->inventory['settings'] ?? null)) {
                        $r->na(__('vCenter settings were not collected.'));

                        return;
                    }
                    $r->total(1);
                    if (trim((string) $setting($c, 'mail.smtp.server')) === '') {
                        $vcAdd($c, $r, __('No SMTP server configured.'));
                    }
                }),

            $this->check('retention', __('Event and task retention'), 'warn', 1,
                __('Enable event and task retention (e.g. 30–180 days); without it the vCenter database grows until it fills up.'),
                ['min_days' => [30, __('Minimum retention (days)')]],
                function (Context $c, array $p, Result $r) use ($vcAdd, $setting) {
                    if (! is_array($c->vc->inventory['settings'] ?? null)) {
                        $r->na(__('vCenter settings were not collected.'));

                        return;
                    }
                    $r->total(2);
                    foreach (['event' => __('Events'), 'task' => __('Tasks')] as $k => $label) {
                        if ($setting($c, "$k.maxAgeEnabled") === false) {
                            $vcAdd($c, $r, __(':what are never deleted (retention disabled).', ['what' => $label]));
                        } elseif (($age = $setting($c, "$k.maxAge")) !== null && (int) $age < $p['min_days']) {
                            $vcAdd($c, $r, __(':what are kept only :n days.', ['what' => $label, 'n' => $age]), 'info');
                        }
                    }
                }),

            $this->check('log_level', __('Verbose vCenter logging'), 'warn', 1,
                __('Set config.log.level back to "info" after troubleshooting; verbose / trivia logging fills the log partition and costs performance.'), [],
                function (Context $c, array $p, Result $r) use ($vcAdd, $setting) {
                    $r->total(1);
                    $lvl = $setting($c, 'config.log.level');
                    if (in_array($lvl, ['verbose', 'trivia'], true)) {
                        $vcAdd($c, $r, __('Log level is :l.', ['l' => $lvl]));
                    }
                }),
        ];
    }
}

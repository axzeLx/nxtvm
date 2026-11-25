<?php

namespace App\Http\Controllers;

use App\Checkup\Config;
use App\Checkup\Registry;
use App\Checkup\Report;
use App\Checkup\Runner;
use App\Support\AiAssist;
use App\Support\Audit;
use App\Support\Modules;
use App\Support\VcScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Checkup (licensed module): problems, best-practice deviations and recommendations — not inventory. */
class CheckupController extends Controller
{
    public function index(Modules $modules, VcScope $scope, Registry $registry, Config $config): View
    {
        $report = new Report(collect($scope->inScope()->modelKeys()), $registry);

        // Not licensed (e.g. trial over): the last results stay readable, only new checkups are not possible.
        // Nothing to show yet → the activation page.
        $locked = ! $modules->enabled(Modules::CHECKUP);
        if ($locked && $report->empty()) {
            return view('checkup.locked', ['license' => $modules->license(), 'trialEnds' => $modules->trialEnds()]);
        }

        return view('checkup.index', [
            'report' => $report,
            'locked' => $locked,
            'vcenters' => $scope->inScope(),
            'config' => $config,
            'ai' => $ai = new AiAssist,
            'prompts' => $ai->enabled() && ! $report->empty() ? $this->prompts($report, $scope, $ai) : [],
        ]);
    }

    /** Prompt per check with findings ("Ask AI"); names anonymized unless switched off in the settings */
    private function prompts(Report $report, VcScope $scope, AiAssist $ai): array
    {
        $versions = AiAssist::versions($scope->inScope());
        $anon = $ai->anonymize() ? AiAssist::anonymizer($scope->inScope()) : null;
        $sev = ['crit' => 'critical', 'warn' => 'warning', 'info' => 'advice'];
        $out = [];
        foreach ($report->problems() as $key => $c) {
            $check = $report->registry->get($key);
            $rows = $report->findings->get($key, collect());
            if ($check->perFinding) {
                // one question per row (each alarm is a different situation); same alarm on other objects is mentioned
                $byAlarm = $rows->groupBy(fn ($f) => Str::beforeLast($f->message, ' — '));
                foreach ($rows as $f) {
                    $alarm = Str::beforeLast($f->message, ' — ');
                    $same = $byAlarm[$alarm]->sortBy(fn ($x) => $x->id === $f->id ? 0 : 1)->values();
                    $out[$key][$f->id] = $ai->prompt('vCenter alarm "'.$alarm.'"', $sev[$f->severity] ?? $f->severity, $same->count(), $c['total'],
                        __('Explain what the alarm means, the likely causes and how to investigate and resolve it.'), $same, $versions, $anon?->fresh());
                }

                continue;
            }
            $out[$key] = $ai->prompt($check->title, $sev[$c['status']] ?? $c['status'],
                $rows->unique(fn ($f) => $f->vcenter_id.$f->object_type.$f->object_name)->count(), $c['total'],
                $check->advice, $rows, $versions, $anon?->fresh());
        }

        return $out;
    }

    /** "Run checkup now" without a license: new checkups need a license key (the last results stay readable). */
    public function license(Modules $modules, VcScope $scope): View|RedirectResponse
    {
        if ($modules->enabled(Modules::CHECKUP)) {
            return redirect()->route('checkup', ['vc' => $scope->key()]);
        }

        return view('checkup.locked', ['license' => $modules->license(), 'trialEnds' => $modules->trialEnds(), 'forRun' => true]);
    }

    public function run(Modules $modules, VcScope $scope, Runner $runner): RedirectResponse
    {
        if (! $modules->enabled(Modules::CHECKUP)) {
            return redirect()->route('checkup.license', ['vc' => $scope->key()]);
        }

        $done = [];
        foreach ($scope->inScope() as $vc) {
            if ($run = $runner->run($vc, 'manual')) {
                $done[$vc->name] = $run->score;
            }
        }
        Audit::log('checkup.run', implode(', ', array_keys($done)) ?: null, ['scores' => $done]);

        return redirect()->route('checkup', ['vc' => $scope->key()])
            ->with('status', $done ? __('Checkup finished.') : __('Nothing to check yet: no inventory has been collected.'));
    }
}

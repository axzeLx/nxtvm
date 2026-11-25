<?php

namespace App\Http\Controllers\Settings;

use App\Checkup\Config;
use App\Checkup\Registry;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Settings → Checkup: schedule, which checks run, their weights / thresholds, and exclusions. */
class CheckupController extends Controller
{
    public function edit(Registry $registry, Config $config, Modules $modules): View
    {
        return view('settings.checkup', ['registry' => $registry, 'config' => $config, 'licensed' => $modules->enabled(Modules::CHECKUP)]);
    }

    public function update(Request $request, Registry $registry, Config $config): RedirectResponse
    {
        if ($request->boolean('reset')) {
            $config->save([]);
            Audit::log('checkup.settings', __('reset to defaults'));

            return redirect()->route('settings.checkup')->with('status', __('Checkup settings reset to defaults.'));
        }

        $request->validate([
            'schedule.mode' => ['required', 'in:manual,schedule'],
            'schedule.hours' => ['required_if:schedule.mode,schedule', 'nullable', 'integer', 'min:'.Config::HOURS['min'], 'max:'.Config::HOURS['max']],
        ]);

        $checks = [];
        foreach ($registry->all() as $key => $check) {
            if (! $request->has("checks.$key")) {    // not in the form: keep what is stored
                if (isset($config->raw()['checks'][$key])) {
                    $checks[$key] = $config->raw()['checks'][$key];
                }

                continue;
            }
            $in = $request->input("checks.$key", []);
            $row = ['on' => (bool) ($in['on'] ?? false)];
            if ($check->severity !== 'info') {
                $row['weight'] = max(0, min(5, (int) ($in['weight'] ?? $check->weight)));
            }
            foreach ($check->params as $name => [$default]) {
                $v = $in['p'][$name] ?? $default;
                $row['p'][$name] = is_string($default) ? mb_substr(trim((string) $v), 0, 500) : max(0, is_int($default) ? (int) $v : (float) $v);
            }
            $checks[$key] = $row;
        }

        $exclude = [];
        foreach (Config::EXCLUDE_TYPES as $t) {
            $exclude[$t] = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $request->input("exclude.$t", ''))), 'strlen'));
        }

        $before = $config->raw();
        $config->save([
            'schedule' => ['mode' => $request->input('schedule.mode'), 'hours' => (int) ($request->input('schedule.hours') ?: $config->hours())],
            'checks' => $checks,
            'exclude' => $exclude,
        ]);
        Audit::log('checkup.settings', null, [
            'schedule' => $config->scheduled() ? __('every :n h', ['n' => $config->hours()]) : __('manual'),
            'disabled' => collect($checks)->reject(fn ($c) => $c['on'])->keys()->join(', ') ?: '—',
            'changed' => $before !== $config->raw(),
        ]);

        return redirect()->route('settings.checkup')->with('status', __('Checkup settings saved. They apply from the next run.'));
    }
}

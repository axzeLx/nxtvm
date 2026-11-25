@extends('layouts.settings', ['title' => __('Checkup')])

{{-- Checkup settings: schedule, exclusions, and per check: on/off, weight in the score, thresholds. --}}
@section('settings')
    @php
        $cats = \App\Checkup\Category::all();
        $raw = $config->raw();
        $sev = ['crit' => ['badge-crit', __('Critical')], 'warn' => ['badge-warn', __('Warning')], 'info' => ['badge-info', __('Advice')]];
    @endphp

    @unless ($licensed)
        <div class="mb-4 rounded-md border border-warn/30 bg-warn/10 px-3 py-2 text-sm text-warn">
            {{ __('The Checkup module is not activated; these settings take effect once a license key is entered (Settings → Modules).') }}
        </div>
    @endunless

    <form method="POST" action="{{ route('settings.checkup') }}" class="space-y-4" x-data="{ mode: @js(old('schedule.mode', $config->scheduled() ? 'schedule' : 'manual')) }">
        @csrf @method('PUT')

        <div class="grid gap-4 lg:grid-cols-2 items-start">
            {{-- schedule --}}
            <div class="card">
                <div class="card-h"><h2 class="card-t">{{ __('Trigger') }}</h2></div>
                <div class="p-4 space-y-3 text-sm">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="radio" name="schedule[mode]" value="manual" x-model="mode" class="mt-1 accent-[var(--color-accent)]">
                        <span><span class="font-medium">{{ __('Manual') }}</span><br><span class="text-xs text-muted">{{ __('Only with "Run checkup now" on the Checkup page.') }}</span></span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="radio" name="schedule[mode]" value="schedule" x-model="mode" class="mt-1 accent-[var(--color-accent)]">
                        <span class="flex-1">
                            <span class="font-medium">{{ __('Scheduled') }}</span>
                            <span class="mt-1 flex items-center gap-2 text-xs text-muted">
                                {{ __('every') }}
                                <input type="number" name="schedule[hours]" min="{{ \App\Checkup\Config::HOURS['min'] }}" max="{{ \App\Checkup\Config::HOURS['max'] }}"
                                       value="{{ old('schedule.hours', $config->hours()) }}" class="input w-20 py-0.5" :class="mode !== 'schedule' && 'opacity-50'">
                                {{ __('hours (1–24), on the last collected inventory') }}
                            </span>
                        </span>
                    </label>
                    @error('schedule.hours') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- exclusions --}}
            <div class="card">
                <div class="card-h">
                    <div>
                        <h2 class="card-t">{{ __('Exclusions') }}</h2>
                        <p class="text-xs text-muted">{{ __('Objects whose name matches are ignored by all checks. One pattern per line, * as wildcard, case-insensitive.') }}</p>
                    </div>
                </div>
                <div class="p-4 grid grid-cols-2 gap-3">
                    @foreach (['vm' => __('VMs'), 'host' => __('Hosts'), 'datastore' => __('Datastores'), 'network' => __('Networks')] as $t => $label)
                        <div>
                            <label class="label" for="ex-{{ $t }}">{{ $label }}</label>
                            <textarea id="ex-{{ $t }}" name="exclude[{{ $t }}]" rows="3" class="input font-mono text-xs" placeholder="{{ $t === 'vm' ? "replica-*\n*-template" : '' }}">{{ old("exclude.$t", implode("\n", $config->exclude()[$t])) }}</textarea>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <p class="text-xs text-muted">
            {{ __('Weight sets how much a check counts in the score (0 = shown but not scored). Advice checks never lower the score.') }}
        </p>

        {{-- checks per category --}}
        @foreach ($registry->byCategory() as $cat => $checks)
            @continue (! $checks)
            <div class="card">
                <div class="card-h"><h2 class="card-t">{{ $cats[$cat][0] }}</h2><span class="text-xs text-muted">{{ trans_choice(':n check|:n checks', count($checks), ['n' => count($checks)]) }}</span></div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr>
                            <th class="w-8" data-nosort>{{ __('On') }}</th>
                            <th data-nosort>{{ __('Check') }}</th>
                            <th class="w-24" data-nosort>{{ __('Severity') }}</th>
                            <th class="w-20" data-nosort>{{ __('Weight') }}</th>
                            <th data-nosort>{{ __('Thresholds') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($checks as $c)
                                @php $params = $config->params($c); @endphp
                                <tr class="align-top" x-data="{ on: {{ $config->enabled($c) ? 'true' : 'false' }} }">
                                    <td>
                                        <input type="hidden" name="checks[{{ $c->key }}][on]" value="0">
                                        <input type="checkbox" name="checks[{{ $c->key }}][on]" value="1" x-model="on" class="mt-0.5 size-4 accent-[var(--color-accent)]">
                                    </td>
                                    <td :class="! on && 'opacity-50'">
                                        <div class="font-medium text-sm">{{ $c->title }}</div>
                                        <div class="text-xs text-muted max-w-xl">{{ $c->advice }}</div>
                                    </td>
                                    <td :class="! on && 'opacity-50'"><span class="badge {{ $sev[$c->severity][0] }}">{{ $sev[$c->severity][1] }}</span></td>
                                    <td :class="! on && 'opacity-50'">
                                        @if ($c->severity === 'info')
                                            <span class="text-xs text-muted">—</span>
                                        @else
                                            <select name="checks[{{ $c->key }}][weight]" class="input py-0.5 w-16">
                                                @foreach (range(0, 5) as $w)
                                                    <option value="{{ $w }}" @selected($config->weight($c) === $w)>{{ $w }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </td>
                                    <td :class="! on && 'opacity-50'">
                                        <div class="flex flex-wrap gap-x-3 gap-y-1.5">
                                            @foreach ($c->params as $name => [$default, $label])
                                                <label class="text-xs text-muted flex items-center gap-1.5 {{ is_string($default) ? 'w-full' : '' }}">
                                                    <span class="whitespace-nowrap">{{ $label }}</span>
                                                    @if (is_string($default))
                                                        <input name="checks[{{ $c->key }}][p][{{ $name }}]" value="{{ $params[$name] }}" class="input py-0.5 font-mono text-xs min-w-72">
                                                    @else
                                                        <input type="number" step="{{ is_float($default) ? '0.1' : '1' }}" min="0" name="checks[{{ $c->key }}][p][{{ $name }}]" value="{{ $params[$name] }}"
                                                               class="input py-0.5 w-20 tabular-nums" title="{{ __('Default') }}: {{ $default }}">
                                                    @endif
                                                </label>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        <div class="sticky bottom-0 -mx-1 px-1 py-3 bg-bg border-t border-line flex items-center gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <button name="reset" value="1" class="btn btn-sm ml-auto" onclick="return confirm(@js(__('Reset all Checkup settings to the defaults?')))">{{ __('Reset to defaults') }}</button>
        </div>
    </form>
@endsection

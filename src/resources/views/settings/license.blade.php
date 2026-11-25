@extends('layouts.settings', ['title' => __('License')])

{{--
    License: status (free / trial / licensed) at the top; below, side by side, how to get a license (or the
    license details when licensed) and the key form; at the bottom the modules the edition includes.
--}}
@section('settings')
    @php
        $edition = $modules->edition();
        $days = $modules->trialDaysLeft();
        $ends = $modules->trialEnds();
        $licensed = $edition === 'licensed';
        // status hero: [badge class, title, sub line]
        [$tone, $title, $sub] = match ($edition) {
            'licensed' => ['ok', __('Licensed'), __('Licensed to :name', ['name' => $license['lic'] ?: '—']).' · '.($license['exp'] ? __('valid until :date', ['date' => $license['exp']->toDateString()]) : __('no expiry'))],
            'trial' => ['accent', __('Trial'), trans_choice(':n day left|:n days left', $days, ['n' => $days]).' · '.__('all modules are available until :date', ['date' => $ends->toDateString()])],
            default => ['muted', __('Free'), __('Inventory, overview and exports.').' '.($ends ? __('The :n-day trial ended on :date.', ['n' => \App\Support\Modules::TRIAL_DAYS, 'date' => $ends->toDateString()]) : __('The trial has ended.'))],
        };
    @endphp

    <div class="space-y-4 max-w-5xl">

        {{-- Status --}}
        <div @class(['card px-5 py-4 flex flex-wrap items-center gap-x-5 gap-y-3 border-l-4',
                     'border-l-ok' => $tone === 'ok', 'border-l-accent' => $tone === 'accent', 'border-l-line' => $tone === 'muted'])>
            <div @class(['inline-flex size-11 shrink-0 items-center justify-center rounded-full',
                         'bg-ok/15 text-ok' => $tone === 'ok', 'bg-accent-soft text-accent' => $tone === 'accent', 'bg-surface-2 text-muted' => $tone === 'muted'])>
                @include('partials.icon', ['name' => $licensed ? 'check-shield' : ($tone === 'accent' ? 'key' : 'lock'), 'class' => 'size-5'])
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-xs uppercase tracking-wide text-muted">{{ __('Edition') }}</div>
                <div class="text-xl font-semibold">{{ $title }}</div>
                <div class="text-sm text-muted">{{ $sub }}</div>
                @if ($edition === 'trial')
                    {{-- trial progress --}}
                    <div class="mt-2 h-1.5 w-full max-w-sm rounded-full bg-surface-2 overflow-hidden">
                        <div class="h-full bg-accent" style="width: {{ round((\App\Support\Modules::TRIAL_DAYS - $days) / \App\Support\Modules::TRIAL_DAYS * 100) }}%"></div>
                    </div>
                @endif
            </div>
            @if ($code = $modules->machineCode())
                <div class="text-right">
                    <div class="text-xs uppercase tracking-wide text-muted">{{ __('Machine code') }}</div>
                    <div class="flex items-center gap-2 justify-end">
                        <span class="font-mono text-base tracking-wider select-all">{{ $code }}</span>
                        <button type="button" class="btn btn-sm !px-1.5" data-copy="{{ $code }}" title="{{ __('Copy') }}">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                            <span data-copy-label class="sr-only">{{ __('Copy') }}</span>
                        </button>
                    </div>
                </div>
            @endif
        </div>

        <div class="grid gap-4 lg:grid-cols-2 items-start">
            {{-- left: how to get a license, or the details of the license in effect --}}
            @if ($licensed)
                <div class="card">
                    <div class="card-h"><h2 class="card-t">{{ __('License details') }}</h2></div>
                    <dl class="grid grid-cols-[8rem_1fr] text-sm">
                        @foreach ([
                            __('Licensed to') => e($license['lic'] ?: '—'),
                            __('Machine code') => '<span class="font-mono text-xs">'.e($license['mid']).'</span>',
                            __('Modules') => e(collect($license['mod'])->map(fn ($m) => \App\Support\Modules::all()[$m]['label'] ?? $m)->join(', ')),
                            __('Issued') => e($license['iat']?->toDateString() ?? '—'),
                            __('Expires') => $license['exp'] ? e($license['exp']->toDateString()) : e(__('Never')),
                        ] as $k => $v)
                            <dt class="px-4 py-1.5 text-muted border-b border-line">{{ $k }}</dt>
                            <dd class="px-4 py-1.5 border-b border-line">{!! $v !!}</dd>
                        @endforeach
                    </dl>
                </div>
            @else
                <div class="card">
                    <div class="card-h"><h2 class="card-t">{{ __('How to get a license') }}</h2></div>
                    <div class="p-4 text-sm">@include('partials.license-howto')</div>
                </div>
            @endif

            {{-- right: the key --}}
            <div class="card">
                <div class="card-h"><h2 class="card-t">{{ $license || $storedInvalid ? __('Replace license key') : __('Activate a license key') }}</h2></div>
                <div class="p-4 space-y-3">
                    @if ($license && ! $license['machine_ok'])
                        <p class="rounded-md border border-crit/30 bg-crit/10 px-3 py-2 text-xs text-crit">
                            {{ __('The stored license key was issued for another machine (:code) and is not in effect.', ['code' => $license['mid']]) }}
                        </p>
                    @elseif ($license && $license['expired'])
                        <p class="rounded-md border border-crit/30 bg-crit/10 px-3 py-2 text-xs text-crit">
                            {{ __('The stored license key expired on :date.', ['date' => $license['exp']->toDateString()]) }}
                        </p>
                    @elseif ($storedInvalid)
                        <p class="rounded-md border border-warn/30 bg-warn/10 px-3 py-2 text-xs text-warn">
                            {{ __('The stored license key is in an older format and is not in effect.') }}
                        </p>
                    @endif
                    <form method="POST" action="{{ route('settings.license.update') }}" class="space-y-3">
                        @csrf @method('PUT')
                        <textarea id="license_key" name="license_key" rows="5" class="input font-mono text-xs break-all" placeholder="NXTVM2.…" required aria-label="{{ __('License key') }}">{{ old('license_key') }}</textarea>
                        @error('license_key') <p class="field-error">{{ $message }}</p> @enderror
                        <div class="flex items-center gap-2">
                            <button class="btn btn-primary">{{ __('Activate') }}</button>
                        </div>
                    </form>
                    @if ($license || $storedInvalid)
                        <form method="POST" action="{{ route('settings.license.destroy') }}" class="pt-3 border-t border-line"
                              onsubmit="return confirm(@js(__('Remove the license key? Licensed modules will be disabled.')))">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">{{ __('Remove license key') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- Modules --}}
        <div class="card">
            <div class="card-h"><h2 class="card-t">{{ __('Modules') }}</h2></div>
            <table class="table">
                <tbody>
                    @foreach (\App\Support\Modules::all() as $key => $m)
                        <tr>
                            <td class="font-medium whitespace-nowrap w-40">{{ $m['label'] }}</td>
                            <td class="text-sm text-muted">{{ $m['description'] }}</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($modules->enabled($key))
                                    <span class="badge badge-ok">{{ $edition === 'trial' ? __('Enabled (trial)') : __('Enabled') }}</span>
                                @else
                                    <span class="badge">{{ __('Needs a license') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection

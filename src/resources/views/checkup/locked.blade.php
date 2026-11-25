@extends('layouts.app', ['title' => __('Checkup')])

{{-- Checkup is a licensed module: blurred, anonymised preview behind an activation notice. --}}
@section('content')
    @php $preview = collect(['webp', 'png', 'jpg'])->map(fn ($e) => "img/checkup-preview.$e")->first(fn ($p) => is_file(public_path($p))); @endphp
    <div class="relative min-h-[calc(100vh-7rem)] overflow-hidden rounded-lg border border-line">
        <div class="absolute inset-0 pointer-events-none select-none blur-[6px] opacity-60 scale-[1.02]" aria-hidden="true" inert>
            @if ($preview)
                <img src="{{ asset($preview) }}" alt="" class="w-full h-full object-cover object-top">
            @else
                {{-- placeholder until the real (anonymised) screenshot is added as public/img/checkup-preview.* --}}
                <div class="p-4 space-y-4">
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        @foreach ([[86, 'ok'], [12, 'warn'], [3, 'crit'], [41, 'info']] as [$n, $c])
                            <div class="card p-4"><div class="h-2 w-20 rounded bg-surface-2 mb-3"></div><div class="text-3xl font-semibold text-{{ $c }}">{{ $n }}</div></div>
                        @endforeach
                    </div>
                    <div class="grid lg:grid-cols-3 gap-3">
                        @foreach (range(1, 3) as $i)
                            <div class="card p-4 space-y-2.5">
                                <div class="h-2.5 w-32 rounded bg-surface-2"></div>
                                @foreach (range(1, 7) as $j)
                                    <div class="flex items-center gap-2">
                                        <span @class(['size-2.5 rounded-full', 'bg-ok' => $j % 3 === 0, 'bg-warn' => $j % 3 === 1, 'bg-crit' => $j % 3 === 2])></span>
                                        <div class="h-2 rounded bg-surface-2" style="width: {{ 30 + (($i * 17 + $j * 23) % 55) }}%"></div>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <div class="card p-4 space-y-2">
                        @foreach (range(1, 8) as $j)
                            <div class="h-2 rounded bg-surface-2" style="width: {{ 45 + ($j * 29 % 50) }}%"></div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="relative flex items-center justify-center min-h-[calc(100vh-7rem)] p-4">
            <div class="card shadow-xl max-w-lg w-full p-6 text-center">
                <div class="mx-auto mb-3 inline-flex size-11 items-center justify-center rounded-full bg-accent-soft text-accent">
                    @include('partials.icon', ['name' => 'lock', 'class' => 'size-5'])
                </div>
                <h1 class="text-lg font-semibold">{{ __('Checkup') }}</h1>
                <p class="mt-1 text-sm">{{ ($forRun ?? false) ? __('You need a license to run a new checkup.') : __('This module needs to be activated.') }}</p>
                <p class="mt-2 text-xs text-muted">
                    @if ($license && $license['machine_ok'] && $license['expired'] && in_array(\App\Support\Modules::CHECKUP, $license['mod'], true))
                        {{ __('The license for this module expired on :date.', ['date' => $license['exp']->toDateString()]) }}
                    @elseif ($license && ! $license['machine_ok'])
                        {{ __('The license key was issued for another machine.') }}
                    @elseif ($trialEnds)
                        {{ __('The :n-day trial ended on :date. Health checks, scoring and recommendations for your vCenter inventory are available with a license key.', ['n' => \App\Support\Modules::TRIAL_DAYS, 'date' => $trialEnds->toDateString()]) }}
                    @else
                        {{ __('The trial has ended.') }}
                        {{ __('Health checks, scoring and recommendations for your vCenter inventory are available with a license key.') }}
                    @endif
                </p>
                @if (auth()->user()->isAdmin())
                    <div class="mt-4 rounded-md border border-line bg-surface-2/50 p-3 text-left text-xs">
                        <p class="font-semibold text-sm mb-2">{{ __('How to get a license') }}</p>
                        @include('partials.license-howto')
                    </div>
                    <a href="{{ route('settings.license') }}" class="btn btn-primary mt-4">{{ __('Enter license key') }}</a>
                @else
                    <p class="mt-4 text-xs text-muted">{{ __('Please contact your administrator: licenses are managed under Settings → Modules.') }}</p>
                @endif
                @if ($forRun ?? false)
                    <div class="mt-3"><a href="{{ route('checkup', ['vc' => app(\App\Support\VcScope::class)->key()]) }}" class="text-xs text-muted hover:text-fg">← {{ __('Back to the last results') }}</a></div>
                @endif
            </div>
        </div>
    </div>
@endsection

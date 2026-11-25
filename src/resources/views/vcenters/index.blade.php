@extends('layouts.app', ['title' => __('vCenters'), 'wide' => true])

@section('content')
    @include('partials.flash')
    @if ($vcenters->isEmpty())
        <div class="card px-3 py-8 text-center text-sm text-muted">
            {{ __('No vCenter configured yet.') }}
            @if (auth()->user()->isAdmin()) <a href="{{ route('settings.vcenters.create') }}" class="text-accent hover:underline">{{ __('Add one') }}</a> @endif
        </div>
    @endif

    @if (auth()->user()->isAdmin() && $vcenters->where('is_enabled', true)->count() > 1)
        <div class="flex justify-end mb-2">
            <button type="button" class="btn btn-sm" data-collect-all>{{ __('Collect all') }}</button>
        </div>
    @endif

    <div class="space-y-3">
        @foreach ($vcenters as $vc)
            @php
                $h = $hosts[$vc->id] ?? null; $v = $vms[$vc->id] ?? null; $d = $ds[$vc->id] ?? null;
                $a = $vc->about ?? []; $inv = $vc->inventory ?? [];
                $cpuPct = Fmt::pct($h?->cpu_used, $h?->cpu_total);
                $memPct = Fmt::pct($h?->mem_used, $h?->mem_total);
                $dsPct = $d ? Fmt::pct($d->cap - $d->free, $d->cap) : null;
                $q = ['vc' => $vc->id];
            @endphp
            <div class="card card-strong">
                {{-- single-line header: name → · address … vCenter version · build --}}
                <div class="card-h">
                    <div class="flex items-center gap-2 min-w-0">
                        @include('partials.dot', ['level' => $vc->status(), 'title' => $vc->last_error])
                        <a href="{{ route('dashboard', $q) }}" class="font-semibold truncate hover:text-accent hover:underline" title="{{ __('Open this vCenter') }}">{{ $vc->name }} <span class="text-muted font-normal">→</span></a>
                        <span class="ml-2 text-xs text-muted font-mono truncate">{{ $vc->host }}@if ($vc->ip && $vc->ip !== $vc->host) · {{ $vc->ip }}@endif</span>
                        @if ($vc->location) <span class="badge">{{ $vc->location }}</span> @endif
                        @unless ($vc->is_enabled) <span class="badge">{{ __('Disabled') }}</span> @endunless
                    </div>
                    @if ($a)
                        <div class="text-xs shrink-0 whitespace-nowrap">
                            <span class="font-medium">vCenter {{ $a['version'] ?? '' }}</span>
                            <span class="text-muted">· build {{ $a['build'] ?? '' }}</span>
                        </div>
                    @endif
                </div>

                {{-- one vCenter per row: counters on the left, capacity bars on the right (stacked on small screens) --}}
                <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(20rem,26rem)] border-b border-line">
                {{-- counters in two rows of five (hairline grid): icon chip + number + label; tone marks running (ok) and alarms (warn) --}}
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-px bg-line border-b lg:border-b-0 border-line">
                    @foreach ([
                        ['datacenter', __('Datacenters'), count($inv['datacenters'] ?? []), 'components', null],
                        ['cluster', __('Clusters'), $clusters[$vc->id] ?? 0, 'components', null],
                        ['server', __('Hosts'), $h->n ?? 0, 'hosts', null],
                        ['cpu', __('CPUs'), $h->cpus ?? 0, 'hosts', null],
                        ['cores', __('Cores'), $h->cores ?? 0, 'hosts', null],
                        ['vm', __('VMs'), $v->n ?? 0, 'vms', null],
                        ['play', __('Running'), $v->on_n ?? 0, 'vms', 'ok'],
                        ['datastore', __('Datastores'), $d->n ?? 0, 'datastores', null],
                        ['network', __('Networks'), $networks[$vc->id] ?? 0, 'networks', null],
                        ['bell', __('Alarms'), $alarms[$vc->id] ?? 0, 'components', ($alarms[$vc->id] ?? 0) ? 'warn' : null],
                    ] as [$icon, $label, $num, $route, $tone])
                        <a href="{{ route($route, $q) }}" class="group flex items-center gap-3 px-4 py-2.5 bg-surface hover:bg-surface-2">
                            <span @class([
                                'inline-flex size-8 shrink-0 items-center justify-center rounded-md',
                                'bg-accent-soft text-accent' => ! $tone,
                                'bg-ok/12 text-ok' => $tone === 'ok',
                                'bg-warn/15 text-warn' => $tone === 'warn',
                            ])>@include('partials.icon', ['name' => $icon])</span>
                            <span class="min-w-0 leading-tight">
                                <span @class(['block text-base font-semibold tabular-nums', 'text-warn' => $tone === 'warn'])>{{ $num }}</span>
                                <span class="block text-[10px] uppercase tracking-wide text-muted truncate group-hover:text-fg">{{ $label }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>

                <div class="px-3 py-2 grid grid-cols-[4.5rem_1fr_auto] gap-x-3 gap-y-1.5 items-center content-center text-xs lg:border-l border-line">
                    <span class="text-muted">CPU</span>
                    @include('partials.bar', ['pct' => $cpuPct])
                    <span class="text-muted tabular-nums whitespace-nowrap">{{ $h ? round($h->cpu_used / 1000).' / '.round($h->cpu_total / 1000).' GHz' : '—' }}</span>

                    <span class="text-muted">{{ __('Memory') }}</span>
                    @include('partials.bar', ['pct' => $memPct])
                    <span class="text-muted tabular-nums whitespace-nowrap">{{ $h ? Fmt::bytes($h->mem_used).' / '.Fmt::bytes($h->mem_total) : '—' }}</span>

                    <span class="text-muted">{{ __('Storage') }}</span>
                    @include('partials.bar', ['pct' => $dsPct])
                    <span class="text-muted tabular-nums whitespace-nowrap">{{ $d ? Fmt::bytes($d->cap - $d->free).' / '.Fmt::bytes($d->cap) : '—' }}</span>
                </div>
                </div>

                <div class="px-3 py-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                    @if ($v)
                        <span>vCPU <b class="text-fg tabular-nums">{{ $v->vcpu }}</b></span>
                        <span>{{ __('vRAM') }} <b class="text-fg">{{ Fmt::mb($v->mem_mb) }}</b></span>
                        @if ($d) <span>{{ __('Provisioned') }} <b class="text-fg">{{ Fmt::bytes($d->prov) }}</b></span> @endif
                        <span>{{ __('Templates') }} <b class="text-fg tabular-nums">{{ $v->templates }}</b></span>
                        <span>{{ __('Snapshots') }} <b @class(['tabular-nums', 'text-warn' => $v->snaps, 'text-fg' => ! $v->snaps])>{{ $v->snaps }}</b></span>
                    @endif
                    @if ($h && $h->lic_cores)
                        <span title="{{ __('Per-core licensing counts at least :n cores per CPU', ['n' => config('nxtvm.license_min_cores')]) }}">
                            {{ __('Licensed cores') }} <b @class(['tabular-nums', 'text-fg' => $h->lic_cores == $h->cores, 'text-warn' => $h->lic_cores > $h->cores])>{{ $h->lic_cores }}</b>
                            <span class="text-muted">({{ __('min :n/CPU', ['n' => config('nxtvm.license_min_cores')]) }})</span>
                        </span>
                    @endif
                    @if ($h && $h->builds > 1) <span class="text-warn">{{ __(':n ESXi builds', ['n' => $h->builds]) }}</span> @endif
                    @if ($h && $h->disconnected) <span class="text-crit">{{ __(':n host(s) disconnected', ['n' => $h->disconnected]) }}</span> @endif
                    @if ($h && $h->maint) <span class="text-warn">{{ __(':n in maintenance', ['n' => $h->maint]) }}</span> @endif
                    @if ($certSoon[$vc->id] ?? 0)
                        <a href="{{ route('certificates', $q) }}" class="text-warn hover:underline">{{ __(':n certificate(s) expiring', ['n' => $certSoon[$vc->id]]) }}</a>
                    @endif
                </div>

                {{-- last update sits right next to the Collect now button --}}
                <div class="px-3 py-1.5 border-t border-line flex items-center justify-end gap-2 text-[11px] text-muted italic">
                    @if ($vc->last_error)
                        <span class="text-crit truncate" title="{{ $vc->last_error }}">{{ __('Last update failed:') }} {{ $vc->last_error }}</span>
                    @else
                        <span>{{ __('Last update') }} {{ Fmt::ago($vc->last_success_at) }}@if ($vc->collect_ms) · {{ round($vc->collect_ms / 1000, 1) }} s @endif</span>
                    @endif
                    <span class="not-italic">@include('partials.collect-button', ['vc' => $vc])</span>
                </div>
            </div>
        @endforeach
    </div>
@endsection

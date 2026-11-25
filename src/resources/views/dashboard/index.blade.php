@extends('layouts.app', ['title' => __('Dashboard'), 'wide' => true])

{{--
    Dashboard of one vCenter. Layout: a row of four summary cards, then three equal-height cards
    (vCenter · clusters & settings · alarms), then three fixed-height scrolling lists with a filter each
    (hosts · datastores · guest OS).
--}}
@section('content')
    @php
        $a = $vc->about ?? []; $inv = $vc->inventory ?? []; $set = $inv['settings'] ?? [];
        $ap = $inv['appliance']['endpoints'] ?? [];
        $ver = ($ap['version']['ok'] ?? false) ? $ap['version']['data'] : null;
        $svcHealth = ($ap['health_services']['ok'] ?? false) ? $ap['health_services']['data'] : null;
        $onoff = fn ($b) => $b ? '<span class="text-ok">'.__('on').'</span>' : '<span class="text-warn">'.__('off').'</span>';
        $q = ['vc' => $vc->id];
        $num = fn ($n) => (int) ($n ?? 0);

        $vmTotal = $num($vms->n); $vmOn = $num($vms->on_n);
        $pctOn = $vmTotal ? round($vmOn / $vmTotal * 100, 1) : 0;

        $cap = $datastores->sum('capacity_bytes'); $free = $datastores->sum('free_bytes');
        $usedPct = Fmt::pct($cap - $free, $cap);
        $dsHigh = $datastores->filter(fn ($d) => Fmt::pct($d->capacity_bytes - $d->free_bytes, $d->capacity_bytes) >= 80)->count();

        $alarmRed = $alarms->where('status', 'red')->count();
        $mailOk = ($set['mail.smtp.server'] ?? '') !== '';
        $lic = $hosts->sum(fn ($h) => $h->licensedCores());
    @endphp

    {{-- ── Summary ─────────────────────────────────────────────── --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-3">
        {{-- VMs --}}
        <a href="{{ route('vms', $q) }}" class="card px-3 py-2.5 flex flex-col gap-2 hover:border-accent/50">
            <div class="flex items-center justify-between">
                <span class="text-[11px] uppercase tracking-wide text-muted">{{ __('Virtual machines') }}</span>
                <span class="badge badge-ok tabular-nums">{{ $pctOn }}% {{ __('running') }}</span>
            </div>
            {{-- 221 VM  ( ▶ 184 running | ■ 37 off ) --}}
            <div class="flex items-center gap-3">
                <span class="text-2xl font-semibold tabular-nums">{{ $vmTotal }} <span class="text-sm font-normal text-muted">VM</span></span>
                <span class="flex items-center gap-3.5 text-xs text-muted tabular-nums">
                    <span class="inline-flex items-center gap-1" title="{{ __('Running') }}">
                        <svg class="size-3 text-ok" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4.5v15a1 1 0 0 0 1.5.86l12.5-7.5a1 1 0 0 0 0-1.72L8.5 3.64A1 1 0 0 0 7 4.5z"/></svg>
                        {{ $vmOn }} {{ __('running') }}
                    </span>
                    <span class="h-3.5 w-px bg-muted/40"></span>
                    <span class="inline-flex items-center gap-1" title="{{ __('Powered off') }}">
                        <svg class="size-3 text-crit" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                        {{ $num($vms->off_n) }} {{ __('off') }}
                    </span>
                    @if ($num($vms->orphaned))
                        <span class="h-3.5 w-px bg-muted/40"></span>
                        <span class="text-crit">{{ $num($vms->orphaned) }} {{ __('orphaned') }}</span>
                    @endif
                </span>
            </div>
            <div class="h-1.5 rounded-full bg-surface-2 overflow-hidden"><div class="h-full bg-ok" style="width: {{ $pctOn }}%"></div></div>
            <div class="flex justify-between text-[11px] text-muted tabular-nums">
                <span>{{ __('Templates') }} <span class="text-fg">{{ $num($vms->templates) }}</span></span>
                <span>{{ __('Snapshots') }} <span @class(['text-warn' => $num($vms->with_snap), 'text-fg' => ! $num($vms->with_snap)])>{{ $num($vms->with_snap) }}</span></span>
                <span>{{ __('Tools not running') }} <span @class(['text-warn' => $num($vms->tools_not), 'text-fg' => ! $num($vms->tools_not)])>{{ $num($vms->tools_not) }}</span></span>
            </div>
        </a>

        {{-- Compute --}}
        <a href="{{ route('hosts', $q) }}" class="card px-3 py-2.5 flex flex-col gap-2 hover:border-accent/50">
            <div class="flex items-center justify-between">
                <span class="text-[11px] uppercase tracking-wide text-muted">{{ __('Compute & hosts') }}</span>
                <span class="badge badge-accent tabular-nums">{{ trans_choice(':n cluster|:n clusters', $clusters->count(), ['n' => $clusters->count()]) }}</span>
            </div>
            {{-- hosts · CPUs · cores, evenly spread with an icon each --}}
            <div class="grid grid-cols-3 divide-x divide-line">
                @foreach ([
                    ['server', $hosts->count(), __('hosts')],
                    ['cpu', $hosts->sum('cpu_sockets'), __('CPUs')],
                    ['cores', $hosts->sum('cpu_cores'), __('cores')],
                ] as [$icon, $n, $label])
                    <div class="flex items-center justify-center gap-2 first:justify-start">
                        <svg class="size-5 text-muted shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! [
                            'server' => '<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/>',
                            'cpu' => '<rect x="6" y="6" width="12" height="12" rx="1.5"/><rect x="9.5" y="9.5" width="5" height="5"/><path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"/>',
                            'cores' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
                        ][$icon] !!}</svg>
                        <span class="text-2xl font-semibold tabular-nums">{{ $n }}</span>
                        <span class="text-xs text-muted">{{ $label }}</span>
                    </div>
                @endforeach
            </div>
            <div class="grid grid-cols-3 gap-2 pt-1.5 border-t border-line text-xs tabular-nums">
                <div><div class="text-[11px] text-muted">vCPU</div><div class="font-medium">{{ $num($vms->vcpu) }}</div></div>
                <div><div class="text-[11px] text-muted">vRAM</div><div class="font-medium">{{ Fmt::mb($vms->mem_mb) }}</div></div>
                <div title="{{ __('Per-core licensing counts at least :n cores per CPU', ['n' => config('nxtvm.license_min_cores')]) }}">
                    <div class="text-[11px] text-muted">{{ __('Licensed cores') }}</div>
                    <div @class(['font-medium', 'text-warn' => $lic > $hosts->sum('cpu_cores')])>{{ $lic }}</div>
                </div>
            </div>
        </a>

        {{-- Storage --}}
        <a href="{{ route('datastores', $q) }}" class="card px-3 py-2.5 flex flex-col gap-2 hover:border-accent/50">
            <div class="flex items-center justify-between">
                <span class="text-[11px] uppercase tracking-wide text-muted">{{ __('Storage') }}</span>
                <span class="badge tabular-nums">{{ $datastores->count() }} {{ __('datastores') }}</span>
            </div>
            <div class="flex items-baseline gap-3">
                <span class="text-2xl font-semibold tabular-nums">{{ Fmt::bytes($cap - $free) }} <span class="text-sm font-normal text-muted">{{ __('used') }}</span></span>
                <span class="text-xs text-muted tabular-nums">{{ __('of') }} {{ Fmt::bytes($cap) }}</span>
            </div>
            @include('partials.bar', ['pct' => $usedPct])
            <div class="flex justify-between text-[11px] text-muted tabular-nums">
                <span>{{ __('Free') }} <span class="text-fg">{{ Fmt::bytes($free) }}</span></span>
                <span>{{ __('Above 80%') }} <span @class(['text-warn' => $dsHigh, 'text-fg' => ! $dsHigh])>{{ $dsHigh }}</span></span>
            </div>
        </a>

        {{-- Health --}}
        <div class="card px-3 py-2.5 flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <span class="text-[11px] uppercase tracking-wide text-muted">{{ __('Health & alarms') }}</span>
                @if ($svcHealth)
                    <span class="badge badge-{{ Fmt::vsLevel($svcHealth) }}">{{ __('services') }} {{ $svcHealth }}</span>
                @endif
            </div>
            <div class="flex items-baseline gap-3">
                <span @class(['text-2xl font-semibold tabular-nums', 'text-crit' => $alarmRed, 'text-warn' => ! $alarmRed && $alarms->count(), 'text-ok' => $alarms->isEmpty()])>{{ $alarms->count() }}</span>
                <span class="text-xs text-muted">{{ __('triggered alarms') }}@if ($alarmRed) · <span class="text-crit">{{ $alarmRed }} red</span>@endif</span>
            </div>
            <div class="grid grid-cols-2 gap-2 pt-1.5 border-t border-line text-xs">
                <div>
                    <div class="text-[11px] text-muted">{{ __('Certificates') }}</div>
                    @if ($certSoon)
                        <a href="{{ route('certificates', $q) }}" class="text-warn hover:underline">{{ __(':n expiring', ['n' => $certSoon]) }}</a>
                    @else
                        <span class="text-ok">{{ __('none expiring soon') }}</span>
                    @endif
                </div>
                <div>
                    <div class="text-[11px] text-muted">{{ __('Mail alerts') }}</div>
                    <span @class(['text-ok' => $mailOk, 'text-warn' => ! $mailOk])>{{ $mailOk ? __('configured') : __('not configured') }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ── vCenter · clusters & settings · alarms (equal height) ──────── --}}
    <div class="grid gap-3 lg:grid-cols-3 items-stretch mb-3">
        {{-- vCenter --}}
        <div class="card flex flex-col">
            <div class="card-h">
                <h2 class="card-t">vCenter</h2>
                <span class="badge font-mono">{{ collect($inv['datacenters'] ?? [])->pluck('name')->implode(', ') ?: '—' }}</span>
            </div>
            <dl class="text-[13px] flex-1 divide-y divide-line">
                @foreach ([
                    [__('Name'), e($vc->name).($vc->location ? ' <span class="badge">'.e($vc->location).'</span>' : '')],
                    [__('Version'), $a ? e($a['version'] ?? '').' <span class="text-muted">· '.e($a['build'] ?? '').'</span>' : null],
                    [__('Patch'), $ver ? '<span title="'.e($ver['summary'] ?? '').'">'.e(substr($ver['install_time'] ?? '', 0, 10)).'</span>' : null],
                    [__('Address'), '<span class="font-mono text-xs">'.e($vc->host.($vc->ip && $vc->ip !== $vc->host ? ' · '.$vc->ip : '')).'</span>'],
                    ['FQDN', e($set['VirtualCenter.FQDN'] ?? '')],
                    [__('Account'), '<span class="font-mono text-xs">'.e($inv['user'] ?? $vc->username).'</span>'],
                    [__('Last update'), $vc->last_error
                        ? '<span class="text-crit" title="'.e($vc->last_error).'">'.e(__('failed')).'</span>'
                        : e(Fmt::ago($vc->last_success_at)).' <span class="text-muted">· '.round(($vc->collect_ms ?? 0) / 1000, 1).' s</span>'],
                ] as [$label, $value])
                    <div class="px-3 py-1.5 flex justify-between gap-4">
                        <dt class="text-muted whitespace-nowrap">{{ $label }}</dt>
                        <dd class="text-right truncate">{!! $value === null || $value === '' ? '<span class="text-muted">—</span>' : $value !!}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Clusters & settings --}}
        <div class="card flex flex-col">
            <div class="card-h"><h2 class="card-t">{{ __('Clusters & settings') }}</h2><span class="text-xs text-muted">{{ $clusters->count() }}</span></div>
            <table class="table" data-nosort>
                <thead><tr><th>{{ __('Cluster') }}</th><th class="text-right">{{ __('Hosts') }}</th><th>HA</th><th>DRS</th><th>EVC</th></tr></thead>
                <tbody>
                    @foreach ($clusters as $c)
                        <tr>
                            <td class="font-medium whitespace-nowrap">
                                <span class="inline-flex items-center gap-1.5">@include('partials.dot', ['level' => Fmt::vsLevel($c->data['overall_status'] ?? null)]) {{ $c->name }}</span>
                            </td>
                            <td class="text-xs text-right tabular-nums">{{ $c->num_hosts }}</td>
                            <td class="text-xs">{!! $onoff($c->ha_enabled) !!}</td>
                            <td class="text-xs">{!! $onoff($c->drs_enabled) !!}</td>
                            <td class="text-xs">{{ $c->data['evc_mode'] ?? __('off') }}</td>
                        </tr>
                    @endforeach
                    @if ($clusters->isEmpty()) <tr><td colspan="5" class="text-center text-muted">{{ __('No clusters.') }}</td></tr> @endif
                </tbody>
            </table>
            <dl class="mt-auto border-t border-line px-3 py-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs">
                @foreach ([
                    [__('Event retention'), e(($set['event.maxAgeEnabled'] ?? false) ? ($set['event.maxAge'] ?? '?').' '.__('days') : __('unlimited'))],
                    [__('Task retention'), e(($set['task.maxAgeEnabled'] ?? false) ? ($set['task.maxAge'] ?? '?').' '.__('days') : __('unlimited'))],
                    [__('Mail server'), $mailOk ? e($set['mail.smtp.server']) : '<span class="text-warn">'.e(__('not configured')).'</span>'],
                    [__('Mail sender'), e($set['mail.sender'] ?? '')],
                    ['SNMP', ($set['snmp.receiver.1.enabled'] ?? false) ? e($set['snmp.receiver.1.name'] ?? '') : e(__('off'))],
                    [__('Log level'), e($set['config.log.level'] ?? '')],
                ] as [$label, $value])
                    <div class="flex justify-between gap-2 min-w-0">
                        <dt class="text-muted whitespace-nowrap">{{ $label }}</dt>
                        <dd class="truncate">{!! $value === '' ? '<span class="text-muted">—</span>' : $value !!}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Alarms --}}
        <div class="card flex flex-col">
            <div class="card-h">
                <h2 class="card-t">{{ __('Triggered alarms') }}</h2>
                <span @class(['badge tabular-nums', 'badge-crit' => $alarmRed, 'badge-warn' => ! $alarmRed && $alarms->count()])>{{ $alarms->count() }}</span>
            </div>
            <div class="flex-1 overflow-y-auto max-h-64 divide-y divide-line">
                @foreach ($alarms as $al)
                    <div class="px-3 py-1.5 flex items-start gap-2">
                        <span class="badge badge-{{ Fmt::vsLevel($al->status) }} mt-px">{{ $al->status }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="text-xs leading-snug">{{ $al->alarm }}</div>
                            <div class="text-[11px] text-muted">{{ $al->entity ?? ($al->entity_type === 'Folder' ? 'vCenter' : '—') }}</div>
                        </div>
                        <span class="text-[11px] text-muted tabular-nums whitespace-nowrap">{{ Fmt::ago($al->triggered_at) }}</span>
                    </div>
                @endforeach
                @if ($alarms->isEmpty()) <p class="px-3 py-4 text-center text-sm text-muted">{{ __('No triggered alarms.') }}</p> @endif
            </div>
        </div>
    </div>

    {{-- ── Lists: fixed height, scrolling, filter per card ───────────── --}}
    <div class="grid gap-3 lg:grid-cols-3">
        {{-- Hosts --}}
        <div class="card flex flex-col h-[38rem]">
            <div class="card-h">
                <h2 class="card-t"><a href="{{ route('hosts', $q) }}" class="hover:text-accent">{{ __('Hosts') }}</a> <span class="text-xs font-normal text-muted" data-count-for="d-hosts">{{ $hosts->count() }}</span></h2>
                <input type="search" data-filter="d-hosts" class="input py-0.5 text-xs w-36" placeholder="{{ __('Filter…') }}" autocomplete="off">
            </div>
            <div class="flex-1 overflow-y-auto">
                <table class="table table-sticky" id="d-hosts">
                    <thead><tr><th>{{ __('Host') }}</th><th>{{ __('Cluster') }}</th><th class="text-right">{{ __('Cores') }}</th><th class="text-right" title="{{ __('VMs running / stopped') }}">VMs <span class="normal-case font-normal">on/off</span></th></tr></thead>
                    <tbody>
                        @foreach ($hosts as $h)
                            @php
                                $pv = $vmsPerHost[$h->moid] ?? null; $on = $num($pv?->on_n); $off = $num($pv?->n) - $on;
                                $lvl = $h->connection_state !== 'connected' ? 'crit' : ($h->maintenance ? 'warn' : Fmt::vsLevel($h->overall_status));
                            @endphp
                            <tr>
                                <td class="whitespace-nowrap" data-sort="{{ $h->name }}">
                                    <a href="{{ route('hosts.show', $h->ref()) }}" class="inline-flex items-center gap-1.5 font-medium hover:text-accent">
                                        @include('partials.dot', ['level' => $lvl, 'title' => $h->maintenance ? __('maintenance') : $h->connection_state])
                                        {{ $h->name }}
                                    </a>
                                </td>
                                <td class="text-xs text-muted">{{ $h->cluster ?? '—' }}</td>
                                <td class="text-xs text-right tabular-nums">{{ $h->cpu_cores }}</td>
                                <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $on }}"><span class="text-ok">{{ $on }}</span><span class="text-muted">/{{ $off }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($hosts->isEmpty()) <p class="px-3 py-4 text-center text-sm text-muted">{{ __('No data yet.') }}</p> @endif
            </div>
        </div>

        {{-- Datastores --}}
        <div class="card flex flex-col h-[38rem]" x-data="{ hideLocal: false }">
            <div class="card-h">
                <div class="flex items-center gap-2">
                    <h2 class="card-t"><a href="{{ route('datastores', $q) }}" class="hover:text-accent">{{ __('Datastores') }}</a> <span class="text-xs font-normal text-muted" data-count-for="d-ds">{{ $datastores->count() }}</span></h2>
                    <label class="cf-check"><input type="checkbox" x-model="hideLocal">{{ __('hide local') }}</label>
                </div>
                <input type="search" data-filter="d-ds" class="input py-0.5 text-xs w-32" placeholder="{{ __('Filter…') }}" autocomplete="off">
            </div>
            <div class="flex-1 overflow-y-auto">
                <table class="table table-sticky" id="d-ds" :class="hideLocal && 'hide-local'">
                    <thead><tr><th>{{ __('Datastore') }}</th><th class="text-right">{{ __('Size') }}</th><th class="text-right">{{ __('Free') }}</th><th>{{ __('Used') }}</th></tr></thead>
                    <tbody>
                        @foreach ($datastores as $d)
                            @php $used = Fmt::pct($d->capacity_bytes - $d->free_bytes, $d->capacity_bytes); $local = $d->data['vmfs']['local'] ?? false; @endphp
                            <tr @if ($local) data-local @endif>
                                <td class="whitespace-nowrap">
                                    <a href="{{ route('datastores.show', $d->ref()) }}" class="font-medium hover:text-accent">{{ $d->name }}</a>
                                    @if ($local) <span class="badge">{{ __('local') }}</span> @endif
                                </td>
                                <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $d->capacity_bytes }}">{{ Fmt::bytes($d->capacity_bytes) }}</td>
                                <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $d->free_bytes }}">{{ Fmt::bytes($d->free_bytes) }}</td>
                                <td class="w-32" data-sort="{{ $used }}">@include('partials.bar', ['pct' => $used])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Guest OS --}}
        <div class="card flex flex-col h-[38rem]">
            <div class="card-h">
                <h2 class="card-t">{{ __('Guest OS') }} <span class="text-xs font-normal text-muted" data-count-for="d-os">{{ $osDist->count() }}</span></h2>
                <input type="search" data-filter="d-os" class="input py-0.5 text-xs w-36" placeholder="{{ __('Filter…') }}" autocomplete="off">
            </div>
            @php
                // totals per OS family; CentOS, Photon OS etc. do not say "Linux" in their name
                $family = fn ($os) => match (true) {
                    (bool) preg_match('/windows/i', (string) $os) => 'Windows',
                    (bool) preg_match('/linux|centos|photon|red hat|rhel|ubuntu|debian|suse|oracle|fedora|rocky|alma|coreos|flatcar/i', (string) $os) => 'Linux',
                    default => __('Other'),
                };
                $groups = $osDist->groupBy(fn ($os) => $family($os->guest_os));
                $families = collect(['Windows', 'Linux', __('Other')])->filter(fn ($k) => $groups->has($k))
                    ->mapWithKeys(fn ($k) => [$k => ['n' => $groups[$k]->sum('n'), 'on' => $groups[$k]->sum('on_n')]]);
            @endphp
            @if ($families->isNotEmpty())
                <div class="px-3 py-1.5 border-b border-line flex items-center justify-center gap-x-3 gap-y-1 flex-wrap text-xs">
                    @foreach ($families as $name => $f)
                        @unless ($loop->first) <span class="h-3.5 w-px bg-line"></span> @endunless
                        <span class="inline-flex items-center gap-1.5 whitespace-nowrap" title="{{ __('running / total') }}">
                            @if ($name === 'Windows')
                                <svg class="size-3.5 shrink-0" viewBox="0 0 16 16" aria-hidden="true"><path fill="#0078d4" d="M0 2.2 6.5 1.3v6.3H0zM7.3 1.2 16 0v7.6H7.3zM0 8.4h6.5v6.3L0 13.8zM7.3 8.4H16V16l-8.7-1.2z"/></svg>
                            @elseif ($name === 'Linux')
                                {{-- simple penguin --}}
                                <svg class="size-4 shrink-0" viewBox="0 0 16 16" aria-hidden="true">
                                    <ellipse cx="8" cy="9" rx="4.6" ry="5.8" fill="currentColor"/>
                                    <ellipse cx="8" cy="10.2" rx="3" ry="4" fill="#fff"/>
                                    <circle cx="6.6" cy="5.4" r=".9" fill="#fff"/><circle cx="9.4" cy="5.4" r=".9" fill="#fff"/>
                                    <circle cx="6.7" cy="5.5" r=".4" fill="#111"/><circle cx="9.3" cy="5.5" r=".4" fill="#111"/>
                                    <path fill="#f5a623" d="M6.9 6.6h2.2L8 7.9zM3.6 14.3c.6-1 1.9-1.2 2.8-.6l-.3 1.3c-1 .4-2 .1-2.5-.7zM12.4 14.3c-.6-1-1.9-1.2-2.8-.6l.3 1.3c1 .4 2 .1 2.5-.7z"/>
                                </svg>
                            @endif
                            <span class="font-medium">{{ $name }}</span>
                            <span class="tabular-nums"><span class="text-ok">{{ $f['on'] }}</span><span class="text-muted">/{{ $f['n'] }}</span></span>
                        </span>
                    @endforeach
                </div>
            @endif
            <div class="flex-1 overflow-y-auto">
                @php $max = max(1, (int) $osDist->max('n')); @endphp
                <table class="table table-sticky" id="d-os">
                    <thead><tr><th>{{ __('Operating system') }}</th><th data-nosort></th><th class="text-right" title="{{ __('running / total') }}">{{ __('On / total') }}</th></tr></thead>
                    <tbody>
                        @foreach ($osDist as $os)
                            <tr>
                                <td class="max-w-0 w-full truncate" title="{{ $os->guest_os }}">{{ $os->guest_os ?: __('unknown') }}</td>
                                <td class="w-24"><div class="h-1.5 w-20 rounded-full bg-surface-2 overflow-hidden"><div class="h-full bg-accent" style="width: {{ max(3, round($os->n / $max * 100)) }}%"></div></div></td>
                                <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $os->n }}"><span class="text-ok">{{ $os->on_n }}</span><span class="text-muted">/{{ $os->n }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($osDist->isEmpty()) <p class="px-3 py-4 text-center text-sm text-muted">{{ __('No data yet.') }}</p> @endif
            </div>
        </div>
    </div>
@endsection

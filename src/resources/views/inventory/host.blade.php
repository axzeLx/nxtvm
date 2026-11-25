@extends('layouts.app', ['title' => $host->name, 'wide' => true])

{{--
    Host detail: a sidebar of tabs (General · Hardware · Network · Storage · Services · Virtual machines).
    The open tab is kept in the URL hash (#network …) so reloads and shared links land on it.
--}}
@section('content')
    @php
        $d = $host->data; $net = $d['network'] ?? []; $adv = $d['advanced'] ?? [];
        $e = fn ($v) => e((string) $v);
        $yesno = fn ($b, $goodWhen = true) => $b === null ? null : ($b === $goodWhen ? '<span class="text-ok">'.__('yes').'</span>' : '<span class="text-warn">'.__('no').'</span>');
        $certDays = $cert ? (int) now()->diffInDays($cert->valid_to, false) : null;
        $cpuPct = Fmt::pct($host->cpu_usage_mhz, $host->cpu_mhz * $host->cpu_cores);
        $memPct = Fmt::pct($host->mem_usage_mb * 1048576, $host->mem_bytes);
        $services = collect($d['services'] ?? []);
        $vmOn = $vms->where('power_state', 'poweredOn')->count();

        // small markers on the tabs when something there needs a look
        $noNtp = empty($d['ntp']['servers']);
        $noSyslog = ($adv['Syslog.global.logHost'] ?? '') === '';
        // single-uplink switch, or a NIC that is down while a switch uses it as uplink (unused NICs being down is normal)
        $uplinks = collect($net['vswitches'] ?? [])->flatMap(fn ($s) => $s['uplinks'])->merge(collect($net['dvs'] ?? [])->flatMap(fn ($s) => $s['uplinks']));
        $netIssue = collect($net['vswitches'] ?? [])->contains(fn ($s) => count($s['uplinks']) < 2)
            || collect($net['pnics'] ?? [])->contains(fn ($p) => ! $p['speed_mb'] && $uplinks->contains($p['device']));
        $deadPaths = collect($d['storage_paths'] ?? [])->sum('dead');
        $sshOn = (bool) ($services->firstWhere('key', 'TSM-SSH')['running'] ?? false);

        $tabs = [
            'general' => [__('General'), 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM12 16v-4M12 8h.01', null, ($noNtp || $noSyslog || ($certDays !== null && $certDays < config('nxtvm.cert_warn_days'))) ? 'warn' : null],
            'hardware' => [__('Hardware'), 'M6 6h12v12H6zM9.5 9.5h5v5h-5zM9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4', null, null],
            'network' => [__('Network'), 'M5 12.55a11 11 0 0 1 14.08 0M1.42 9a16 16 0 0 1 21.16 0M8.53 16.11a6 6 0 0 1 6.95 0M12 20h.01', count($net['vswitches'] ?? []) + count($net['dvs'] ?? []), $netIssue ? 'warn' : null],
            'storage' => [__('Storage'), 'M12 3c-4.97 0-9 1.34-9 3s4.03 3 9 3 9-1.34 9-3-4.03-3-9-3zM3 6v6c0 1.66 4.03 3 9 3s9-1.34 9-3V6M3 12v6c0 1.66 4.03 3 9 3s9-1.34 9-3v-6', $datastores->count(), $deadPaths ? 'crit' : null],
            'services' => [__('Services'), 'M4 6h16M4 12h16M4 18h16M8 6v0M8 12v0M8 18v0', $services->where('running', true)->count().'/'.$services->count(), $sshOn ? 'warn' : null],
            'vms' => [__('Virtual machines'), 'M3 5h18v11H3zM8 20h8M12 16v4', $vms->count(), null],
        ];
    @endphp

    {{-- title row stays under the fixed header (top bar 48px + vCenter bar 37px + border) --}}
    <div class="sticky top-[86px] z-30 -mx-4 -mt-4 mb-3 px-4 py-2.5 bg-bg border-b border-line flex flex-wrap items-center gap-2">
        <h1 class="text-base font-semibold"><span class="font-normal text-muted">{{ __('Host') }}:</span> {{ $host->name }}</h1>
        {{-- vSphere overall status (alarm based health), not reachability --}}
        @include('partials.dot', ['level' => Fmt::vsLevel($host->overall_status), 'title' => __('vSphere status').': '.($host->overall_status ?? '—')])
        @if ($host->maintenance) <span class="badge badge-warn">{{ __('maintenance') }}</span> @endif
        @if ($host->connection_state !== 'connected') <span class="badge badge-crit">{{ $host->connection_state }}</span> @endif
        <span class="ml-auto text-xs text-muted">{{ $host->vendor }} {{ $host->model }} · ESXi {{ $host->version }} · {{ $host->build }}</span>
    </div>

    <div x-data="{
            tab: 'general',
            tabs: @js(array_keys($tabs)),
            init() {
                const h = location.hash.slice(1);
                if (this.tabs.includes(h)) this.tab = h;
                window.addEventListener('hashchange', () => { const t = location.hash.slice(1); if (this.tabs.includes(t)) this.tab = t; });
            },
            open(t) { this.tab = t; history.replaceState(null, '', '#' + t); },
         }" class="flex flex-col md:flex-row gap-3 items-start">

        {{-- Sidebar --}}
        <nav class="card p-1 w-full md:w-52 shrink-0 md:sticky md:top-[9rem] flex md:flex-col gap-0.5 overflow-x-auto">
            @foreach ($tabs as $key => [$label, $icon, $count, $flag])
                <button type="button" @click="open(@js($key))"
                        :class="tab === @js($key) ? 'bg-surface-2 text-fg font-medium' : 'text-muted hover:text-fg hover:bg-surface-2/60'"
                        class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm text-left whitespace-nowrap cursor-pointer">
                    <svg class="size-4 shrink-0" :class="tab === @js($key) && 'text-accent'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                    <span>{{ $label }}</span>
                    @if ($flag) <span class="dot bg-{{ $flag }}" title="{{ __('Needs attention') }}"></span> @endif
                    @if ($count !== null) <span class="ml-auto text-[11px] text-muted tabular-nums">{{ $count }}</span> @endif
                </button>
            @endforeach
        </nav>

        <div class="flex-1 min-w-0 w-full">

            {{-- General: ESXi software, certificate, uptime --}}
            <section x-show="tab === 'general'" class="grid gap-3 xl:grid-cols-2 items-start">
                @include('inventory.kv', ['title' => 'ESXi', 'rows' => [
                    __('Cluster') => $e($host->cluster ?? __('standalone')),
                    __('Version') => $e($d['full_name'] ?? null),
                    __('Build') => $e($host->build),
                    __('Boot time') => $e(Fmt::time($host->boot_time)),
                    __('Uptime') => $e(Fmt::duration($d['uptime_s'] ?? null)),
                    __('Lockdown') => $e(str_replace('lockdown', '', $d['lockdown'] ?? '')),
                    'NTP' => $noNtp ? '<span class="text-crit">'.__('not configured').'</span>' : $e(implode(', ', $d['ntp']['servers'])),
                    __('Time zone') => $e($d['ntp']['timezone'] ?? null),
                    'Syslog' => $noSyslog ? '<span class="text-warn">'.__('not configured').'</span>' : $e($adv['Syslog.global.logHost']),
                    __('Scratch') => '<span class="font-mono text-xs">'.$e($adv['ScratchConfig.CurrentScratchLocation'] ?? '').'</span>',
                    __('License') => $license ? $e($license->name) : null,
                ]])
                @include('inventory.kv', ['title' => __('Certificate'), 'rows' => $cert ? [
                    __('Subject') => $e($cert->subject),
                    __('Issuer') => $e($cert->issuer),
                    __('Valid from') => $e(Fmt::date($cert->valid_from)),
                    __('Expires') => '<span class="text-'.\App\Services\Collector\Certificates::level($certDays).'">'.$e(Fmt::date($cert->valid_to)).'</span> <span class="text-muted">('.$e($certDays).' '.__('days').')</span>',
                ] : [__('Certificate') => null]])
            </section>

            {{-- Hardware: hardware and usage --}}
            <section x-show="tab === 'hardware'" x-cloak class="grid gap-3 xl:grid-cols-2 items-start">
                @include('inventory.kv', ['title' => __('Hardware'), 'rows' => [
                    __('Model') => $e($host->vendor.' '.$host->model),
                    __('Serial') => '<span class="font-mono">'.$e($host->serial).'</span>',
                    'BIOS' => $e(($d['bios']['version'] ?? '').' ('.substr($d['bios']['date'] ?? '', 0, 10).')'),
                    'CPU' => $e($host->cpu_model),
                    __('Topology') => $e($host->cpu_sockets.' × '.($host->coresPerCpu() ?? '?').' '.__('cores').' = '.$host->cpu_cores.' '.__('cores').' · '.$host->cpu_threads.' '.__('threads')),
                    __('Licensed cores') => $e($host->licensedCores()),
                    'Hyper-Threading' => $yesno($d['hyperthreading'] ?? null),
                    __('Memory') => $e(Fmt::bytes($host->mem_bytes)),
                    __('NICs / HBAs') => $e(($d['nics'] ?? '—').' / '.($d['hbas'] ?? '—')),
                    __('Power policy') => $e($d['power_policy'] ?? null),
                ]])
                <div class="card">
                    <div class="card-h"><h2 class="card-t">{{ __('Usage') }}</h2></div>
                    <div class="px-3 py-3 grid grid-cols-[4.5rem_1fr_auto] gap-x-3 gap-y-3 items-center text-xs">
                        <span class="text-muted">CPU</span>
                        @include('partials.bar', ['pct' => $cpuPct])
                        <span class="tabular-nums whitespace-nowrap text-muted">{{ round(($host->cpu_usage_mhz ?? 0) / 1000, 1) }} / {{ round($host->cpu_mhz * $host->cpu_cores / 1000, 1) }} GHz</span>
                        <span class="text-muted">{{ __('Memory') }}</span>
                        @include('partials.bar', ['pct' => $memPct])
                        <span class="tabular-nums whitespace-nowrap text-muted">{{ Fmt::mb($host->mem_usage_mb) }} / {{ Fmt::bytes($host->mem_bytes) }}</span>
                    </div>
                    <dl class="border-t border-line px-3 py-2 grid grid-cols-3 gap-2 text-xs">
                        <div><dt class="text-[11px] text-muted">VMs</dt><dd class="tabular-nums"><span class="text-ok">{{ $vmOn }}</span><span class="text-muted">/{{ $vms->count() }}</span></dd></div>
                        <div><dt class="text-[11px] text-muted">vCPU</dt><dd class="tabular-nums">{{ $vms->where('power_state', 'poweredOn')->sum('vcpu') }} <span class="text-muted">/ {{ $host->cpu_threads }} {{ __('threads') }}</span></dd></div>
                        <div><dt class="text-[11px] text-muted">vRAM</dt><dd class="tabular-nums">{{ Fmt::mb($vms->where('power_state', 'poweredOn')->sum('mem_mb')) }}</dd></div>
                    </dl>
                </div>
            </section>

            {{-- Network: virtual switches, VMkernel adapters, physical NICs --}}
            <section x-show="tab === 'network'" x-cloak class="space-y-3">
                <div class="card px-3 py-2 flex flex-wrap gap-x-6 gap-y-1 text-xs">
                    <span><span class="text-muted">DNS</span> {{ trim(($net['dns']['hostname'] ?? '').'.'.($net['dns']['domain'] ?? ''), '.') ?: '—' }}</span>
                    <span><span class="text-muted">{{ __('DNS servers') }}</span> <span class="font-mono">{{ implode(', ', $net['dns']['servers'] ?? []) ?: '—' }}</span></span>
                    <span><span class="text-muted">{{ __('Gateway') }}</span> <span class="font-mono">{{ $net['gateway'] ?? '—' }}</span></span>
                </div>
                <div class="card overflow-x-auto">
                    <div class="card-h"><h2 class="card-t">{{ __('Virtual switches') }}</h2></div>
                    <table class="table">
                        <thead><tr><th>{{ __('Switch') }}</th><th class="text-right">MTU</th><th>{{ __('Uplinks') }}</th><th>{{ __('Active / standby') }}</th></tr></thead>
                        <tbody>
                            @foreach ($net['vswitches'] ?? [] as $s)
                                <tr>
                                    <td class="font-medium">{{ $s['name'] }}</td>
                                    <td class="text-right tabular-nums text-xs">{{ $s['mtu'] }}</td>
                                    <td @class(['text-xs font-mono', 'text-warn' => count($s['uplinks']) < 2]) @if (count($s['uplinks']) < 2) title="{{ __('No uplink redundancy') }}" @endif>{{ implode(', ', $s['uplinks']) ?: '—' }}</td>
                                    <td class="text-xs font-mono">{{ implode(', ', $s['active_nics']) }} <span class="text-muted">/ {{ implode(', ', $s['standby_nics']) ?: '—' }}</span></td>
                                </tr>
                            @endforeach
                            @foreach ($net['dvs'] ?? [] as $s)
                                <tr>
                                    <td class="font-medium">{{ $s['dvs_name'] }} <span class="badge">dvs</span></td>
                                    <td class="text-right tabular-nums text-xs">{{ $s['mtu'] }}</td>
                                    <td class="text-xs font-mono">{{ implode(', ', $s['uplinks']) }}</td>
                                    <td></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="grid gap-3 xl:grid-cols-2 items-start">
                    <div class="card overflow-x-auto">
                        <div class="card-h"><h2 class="card-t">{{ __('VMkernel adapters') }}</h2></div>
                        <table class="table">
                            <thead><tr><th>vmk</th><th>{{ __('Port group') }}</th><th>IP</th><th class="text-right">MTU</th><th>{{ __('Stack') }}</th></tr></thead>
                            <tbody>
                                @foreach ($net['vmks'] ?? [] as $v)
                                    <tr>
                                        <td class="font-mono text-xs">{{ $v['device'] }}</td>
                                        <td class="text-xs">{{ $v['portgroup'] ?? $v['dvpg_key'] }}</td>
                                        <td class="font-mono text-xs">{{ $v['ip'] }}<span class="text-muted">/{{ $v['mask'] }}</span>@if ($v['dhcp']) <span class="badge">dhcp</span>@endif</td>
                                        <td class="text-right tabular-nums text-xs">{{ $v['mtu'] }}</td>
                                        <td class="text-xs text-muted">{{ $v['stack'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="card overflow-x-auto">
                        <div class="card-h"><h2 class="card-t">{{ __('Physical NICs') }}</h2></div>
                        <table class="table">
                            <thead><tr><th>NIC</th><th>{{ __('Driver') }}</th><th class="text-right">{{ __('Speed') }}</th><th>MAC</th></tr></thead>
                            <tbody>
                                @foreach ($net['pnics'] ?? [] as $p)
                                    <tr>
                                        <td class="font-mono text-xs">{{ $p['device'] }}</td>
                                        <td class="text-xs">{{ $p['driver'] }}</td>
                                        <td class="text-right text-xs tabular-nums" data-sort="{{ $p['speed_mb'] }}">@if ($p['speed_mb']) {{ $p['speed_mb'] >= 1000 ? ($p['speed_mb'] / 1000).' G' : $p['speed_mb'].' M' }} @elseif ($uplinks->contains($p['device'])) <span class="text-crit" title="{{ __('Down, used as uplink') }}">{{ __('down') }}</span> @else <span class="text-muted" title="{{ __('Not used by any switch') }}">{{ __('down') }}</span> @endif</td>
                                        <td class="font-mono text-xs text-muted">{{ $p['mac'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            {{-- Storage: datastores, storage adapters, LUN paths --}}
            <section x-show="tab === 'storage'" x-cloak class="space-y-3">
                <div class="card overflow-x-auto">
                    <div class="card-h"><h2 class="card-t">{{ __('Datastores') }}</h2><span class="text-xs text-muted">{{ $datastores->count() }}</span></div>
                    <table class="table">
                        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Type') }}</th><th class="text-right">{{ __('Capacity') }}</th><th class="text-right">{{ __('Free') }}</th><th>{{ __('Used') }}</th></tr></thead>
                        <tbody>
                            @foreach ($datastores as $ds)
                                @php $used = Fmt::pct($ds->capacity_bytes - $ds->free_bytes, $ds->capacity_bytes); @endphp
                                <tr>
                                    <td class="text-xs font-medium whitespace-nowrap"><a href="{{ route('datastores.show', $ds->ref()) }}" class="hover:text-accent">{{ $ds->name }}</a> @if ($ds->data['vmfs']['local'] ?? false) <span class="badge">{{ __('local') }}</span> @endif</td>
                                    <td class="text-xs">{{ $ds->type }}</td>
                                    <td class="text-xs text-right tabular-nums" data-sort="{{ $ds->capacity_bytes }}">{{ Fmt::bytes($ds->capacity_bytes) }}</td>
                                    <td class="text-xs text-right tabular-nums" data-sort="{{ $ds->free_bytes }}">{{ Fmt::bytes($ds->free_bytes) }}</td>
                                    <td class="w-40" data-sort="{{ $used }}">@include('partials.bar', ['pct' => $used])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="grid gap-3 xl:grid-cols-2 items-start">
                    <div class="card overflow-x-auto">
                        <div class="card-h"><h2 class="card-t">{{ __('Storage adapters') }}</h2></div>
                        <table class="table">
                            <thead><tr><th>HBA</th><th>{{ __('Type') }}</th><th>{{ __('Model') }}</th><th>{{ __('Driver') }}</th><th>WWPN</th></tr></thead>
                            <tbody>
                                @foreach ($d['hba'] ?? [] as $a)
                                    <tr>
                                        <td class="font-mono text-xs">{{ $a['device'] }}</td>
                                        <td class="text-xs">{{ $a['type'] }}</td>
                                        <td class="text-xs">{{ $a['model'] }}</td>
                                        <td class="text-xs text-muted">{{ $a['driver'] }}</td>
                                        <td class="font-mono text-xs text-muted">{{ $a['wwpn'] ? implode(':', str_split(str_pad($a['wwpn'], 16, '0', STR_PAD_LEFT), 2)) : '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="card overflow-x-auto">
                        <div class="card-h"><h2 class="card-t">{{ __('LUN paths') }}</h2>@if ($deadPaths) <span class="badge badge-crit">{{ $deadPaths }} {{ __('dead') }}</span> @endif</div>
                        <table class="table">
                            <thead><tr><th>{{ __('Device') }}</th><th>{{ __('Array') }}</th><th>{{ __('Policy') }}</th><th class="text-right">{{ __('Paths') }}</th><th class="text-right">{{ __('Dead') }}</th></tr></thead>
                            <tbody>
                                @foreach ($d['storage_paths'] ?? [] as $l)
                                    <tr>
                                        <td class="font-mono text-xs">{{ $l['canonical'] }} @if ($l['local']) <span class="badge">{{ __('local') }}</span> @endif</td>
                                        <td class="text-xs">{{ $l['vendor'] }} {{ $l['model'] }}</td>
                                        <td class="text-xs text-muted">{{ $l['policy'] }}</td>
                                        <td @class(['text-right tabular-nums text-xs', 'text-warn' => ! $l['local'] && $l['paths'] < 2])>{{ $l['active'] }}/{{ $l['paths'] }}</td>
                                        <td @class(['text-right tabular-nums text-xs', 'text-crit font-semibold' => $l['dead']])>{{ $l['dead'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            {{-- Services --}}
            <section x-show="tab === 'services'" x-cloak>
                <div class="card">
                    <div class="card-h">
                        <h2 class="card-t">{{ __('Services') }} <span class="text-xs font-normal text-muted" data-count-for="h-svc">{{ $services->count() }}</span></h2>
                        <input type="search" data-filter="h-svc" class="input py-0.5 text-xs w-40" placeholder="{{ __('Filter…') }}" autocomplete="off">
                    </div>
                    <table class="table table-sticky table-sticky-under-title" id="h-svc">
                        <thead><tr><th>{{ __('Service') }}</th><th>{{ __('Key') }}</th><th>{{ __('Status') }}</th><th>{{ __('Startup policy') }}</th></tr></thead>
                        <tbody>
                            @foreach ($services->sortBy('label') as $s)
                                <tr>
                                    <td class="text-xs">{{ $s['label'] }}</td>
                                    <td class="text-xs text-muted font-mono">{{ $s['key'] }}</td>
                                    <td class="text-xs" data-sort="{{ $s['running'] ? 0 : 1 }}">
                                        @if ($s['running'])
                                            <span @class(['text-ok' => $s['key'] !== 'TSM-SSH', 'text-warn' => $s['key'] === 'TSM-SSH'])>●</span> {{ __('running') }}
                                        @else
                                            <span class="text-muted">○ {{ __('stopped') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-xs text-muted">{{ $s['policy'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Virtual machines --}}
            <section x-show="tab === 'vms'" x-cloak>
                <div class="card">
                    <div class="card-h">
                        <h2 class="card-t">{{ __('Virtual machines') }} <span class="text-xs font-normal text-muted"><span data-count-for="h-vms">{{ $vms->count() }}</span> · <span class="text-ok">{{ $vmOn }}</span> {{ __('running') }}</span></h2>
                        <input type="search" data-filter="h-vms" class="input py-0.5 text-xs w-40" placeholder="{{ __('Filter…') }}" autocomplete="off">
                    </div>
                    <table class="table table-sticky table-sticky-under-title" id="h-vms">
                        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Power') }}</th><th class="text-right">vCPU</th><th class="text-right">RAM</th><th class="text-right">{{ __('Used') }}</th><th>IP</th><th>{{ __('Guest OS') }}</th><th>Tools</th></tr></thead>
                        <tbody>
                            @foreach ($vms as $vm)
                                @php $toolsBad = $vm->power_state === 'poweredOn' && $vm->tools_running !== 'guestToolsRunning'; @endphp
                                <tr>
                                    <td class="whitespace-nowrap" data-sort="{{ $vm->name }}">
                                        <a href="{{ route('vms.show', $vm->ref()) }}" class="inline-flex items-center gap-1.5 font-medium hover:text-accent">
                                            @include('partials.dot', ['level' => Fmt::vsLevel($vm->overall_status)])
                                            {{ $vm->name }}
                                        </a>
                                        @if ($vm->template) <span class="badge ml-1">{{ __('template') }}</span> @endif
                                    </td>
                                    <td class="text-xs"><span @class(['text-ok' => $vm->power_state === 'poweredOn', 'text-muted' => $vm->power_state !== 'poweredOn'])>{{ Fmt::state($vm->power_state) }}</span></td>
                                    <td class="text-xs text-right tabular-nums">{{ $vm->vcpu }}</td>
                                    <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $vm->mem_mb }}">{{ Fmt::mb($vm->mem_mb) }}</td>
                                    <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $vm->used_bytes }}">{{ Fmt::bytes($vm->used_bytes) }}</td>
                                    <td class="text-xs font-mono whitespace-nowrap">{{ $vm->ip }}</td>
                                    <td class="text-xs text-muted max-w-56 truncate" title="{{ $vm->guest_os }}">{{ $vm->guest_os }}</td>
                                    <td class="text-xs whitespace-nowrap">
                                        @if ($vm->power_state !== 'poweredOn') <span class="text-muted">—</span>
                                        @else <span @class(['text-ok' => ! $toolsBad, 'text-crit' => $toolsBad])>{{ Fmt::state($vm->tools_running) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($vms->isEmpty()) <p class="px-3 py-4 text-center text-sm text-muted">{{ __('No virtual machines on this host.') }}</p> @endif
                </div>
            </section>
        </div>
    </div>
@endsection

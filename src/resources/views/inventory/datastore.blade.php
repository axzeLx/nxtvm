@extends('layouts.app', ['title' => $ds->name, 'wide' => true])

{{--
    Datastore detail, same structure as the host page: sticky title row + sidebar tabs
    (General · Hosts · Paths · Virtual machines). The open tab is kept in the URL hash.
--}}
@section('content')
    @php
        $d = $ds->data; $vmfs = $d['vmfs'] ?? null; $nas = $d['nas'] ?? null;
        $e = fn ($v) => e((string) $v);
        $yesno = fn ($b) => $b === null ? null : ($b ? __('yes') : __('no'));
        $local = $vmfs['local'] ?? false;
        $used = $ds->capacity_bytes - $ds->free_bytes;
        $usedPct = Fmt::pct($used, $ds->capacity_bytes);
        $over = Fmt::pct($ds->provisioned_bytes, $ds->capacity_bytes);
        $vmOn = $vms->where('power_state', 'poweredOn')->count();

        $mountIssue = $mounts->contains(fn ($m) => ! $m['mounted'] || ! $m['accessible']);
        $deadPaths = $paths->sum('dead');
        $singlePath = $paths->contains(fn ($l) => ! $l['local'] && $l['paths'] < 2);
        $level = ! $ds->accessible ? 'crit' : Fmt::vsLevel($d['overall_status'] ?? null);

        $tabs = [
            'general' => [__('General'), 'info', null, ($usedPct >= 90 ? 'crit' : ($usedPct >= 80 ? 'warn' : null))],
            'hosts' => [__('Hosts'), 'server', $mounts->count(), $mountIssue ? 'crit' : null],
            'vms' => [__('Virtual machines'), 'vm', $vms->count(), null],
        ];
        if ($extents) {
            $tabs = array_slice($tabs, 0, 2, true)
                + ['paths' => [__('Paths'), 'network', $paths->count(), $deadPaths ? 'crit' : ($singlePath ? 'warn' : null)]]
                + array_slice($tabs, 2, null, true);
        }
    @endphp

    {{-- title row stays under the fixed header --}}
    <div class="sticky top-[86px] z-30 -mx-4 -mt-4 mb-3 px-4 py-2.5 bg-bg border-b border-line flex flex-wrap items-center gap-2">
        <h1 class="text-base font-semibold"><span class="font-normal text-muted">{{ __('Datastore') }}:</span> {{ $ds->name }}</h1>
        <span class="badge">{{ $ds->type }}{{ ($vmfs['version'] ?? null) ? ' '.$vmfs['version'] : '' }}</span>
        @if ($vmfs['ssd'] ?? false) <span class="badge">SSD</span> @endif
        {{-- vSphere overall status (alarm based health); red when the datastore is not accessible --}}
        @include('partials.dot', ['level' => $level, 'title' => $ds->accessible ? __('vSphere status').': '.($d['overall_status'] ?? '—') : __('inaccessible')])
        @if ($local) <span class="badge">{{ __('local') }}</span> @endif
        @unless ($ds->accessible) <span class="badge badge-crit">{{ __('inaccessible') }}</span> @endunless
        @if (($d['maintenance'] ?? 'normal') !== 'normal') <span class="badge badge-warn">{{ $d['maintenance'] }}</span> @endif
        <span class="ml-auto flex items-center gap-2 text-xs text-muted">
            <span class="tabular-nums">{{ Fmt::bytes($used) }} / {{ Fmt::bytes($ds->capacity_bytes) }}</span>
            <span class="w-32">@include('partials.bar', ['pct' => $usedPct])</span>
        </span>
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
                    <span :class="tab === @js($key) && 'text-accent'">@include('partials.icon', ['name' => $icon])</span>
                    <span>{{ $label }}</span>
                    @if ($flag) <span class="dot bg-{{ $flag }}" title="{{ __('Needs attention') }}"></span> @endif
                    @if ($count !== null) <span class="ml-auto text-[11px] text-muted tabular-nums">{{ $count }}</span> @endif
                </button>
            @endforeach
        </nav>

        <div class="flex-1 min-w-0 w-full">

            {{-- General: properties and capacity --}}
            <section x-show="tab === 'general'" class="grid gap-3 xl:grid-cols-2 items-start">
                @include('inventory.kv', ['title' => __('Datastore'), 'rows' => [
                    __('Name') => $e($ds->name),
                    __('Type') => $e($ds->type.(($vmfs['version'] ?? null) ? ' '.$vmfs['version'] : '')),
                    __('Backing') => $nas
                        ? '<span class="font-mono text-xs">'.$e(($nas['server'] ?? '').':'.($nas['path'] ?? '')).'</span> <span class="text-muted">'.$e($nas['type'] ?? '').'</span>'
                        : '<span class="font-mono text-xs">'.collect($extents)->map(fn ($x) => e($x))->implode('<br>').'</span>',
                    'SSD' => $vmfs ? $yesno($vmfs['ssd'] ?? null) : null,
                    __('Local') => $vmfs ? $yesno($local) : null,
                    __('Block size') => ($vmfs['block_mb'] ?? null) ? $e($vmfs['block_mb'].' MB') : null,
                    __('Datacenter') => $e($ds->datacenter),
                    __('Datastore cluster') => $e($d['cluster'] ?? null),
                    __('Shared') => $yesno($d['multiple_host_access'] ?? null),
                    __('Accessible') => $ds->accessible ? '<span class="text-ok">'.__('yes').'</span>' : '<span class="text-crit">'.__('no').'</span>',
                    __('Maintenance') => $e($d['maintenance'] ?? null),
                    'URL' => '<span class="font-mono text-[11px] break-all">'.$e($d['url'] ?? '').'</span>',
                ]])

                <div class="card">
                    <div class="card-h"><h2 class="card-t">{{ __('Capacity') }}</h2></div>
                    <div class="px-3 py-3">
                        <div class="flex items-baseline gap-2 mb-2">
                            <span class="text-2xl font-semibold tabular-nums">{{ Fmt::bytes($used) }}</span>
                            <span class="text-sm text-muted">{{ __('used of') }} {{ Fmt::bytes($ds->capacity_bytes) }}</span>
                        </div>
                        @include('partials.bar', ['pct' => $usedPct])
                    </div>
                    <dl class="border-t border-line divide-y divide-line text-[13px]">
                        @foreach ([
                            [__('Free'), Fmt::bytes($ds->free_bytes), null],
                            [__('Provisioned'), Fmt::bytes($ds->provisioned_bytes), null],
                            [__('Uncommitted'), Fmt::bytes($d['uncommitted_bytes'] ?? 0), null],
                            [__('Provisioned %'), $over !== null ? $over.'%'.($over > 100 ? ' · '.__('overcommitted') : '') : '—', match (true) { $over >= 200 => 'crit', $over >= 150 => 'warn', $over > 100 => 'info', default => null }],
                            [__('VM disks here'), Fmt::bytes($vms->sum('ds_bytes')).' · '.$vms->sum('ds_disks').' '.__('disks'), null],
                            [__('Hosts'), $mounts->count(), null],
                            ['VMs', $vmOn.' '.__('running').' / '.$vms->count(), null],
                        ] as [$label, $value, $tone])
                            <div class="px-3 py-1.5 flex justify-between gap-4">
                                <dt class="text-muted">{{ $label }}</dt>
                                <dd @class(['tabular-nums', 'text-info' => $tone === 'info', 'text-warn' => $tone === 'warn', 'text-crit' => $tone === 'crit'])>{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </section>

            {{-- Hosts: mount state per host --}}
            <section x-show="tab === 'hosts'" x-cloak>
                <div class="card overflow-x-auto">
                    <div class="card-h"><h2 class="card-t">{{ __('Mounted on hosts') }}</h2><span class="text-xs text-muted">{{ $mounts->count() }}</span></div>
                    <table class="table">
                        <thead><tr><th>{{ __('Host') }}</th><th>{{ __('Cluster') }}</th><th>{{ __('Mounted') }}</th><th>{{ __('Accessible') }}</th><th>{{ __('Access mode') }}</th><th class="text-right">VMs</th></tr></thead>
                        <tbody>
                            @foreach ($mounts as $m)
                                @php $h = $m['row']; @endphp
                                <tr>
                                    <td class="whitespace-nowrap" data-sort="{{ $h?->name }}">
                                        @if ($h)
                                            <a href="{{ route('hosts.show', $h->ref()) }}#storage" class="inline-flex items-center gap-1.5 font-medium hover:text-accent">
                                                @include('partials.dot', ['level' => $h->connection_state !== 'connected' ? 'crit' : Fmt::vsLevel($h->overall_status)])
                                                {{ $h->name }}
                                            </a>
                                        @else <span class="font-mono text-xs text-muted">{{ $m['host'] }}</span> @endif
                                    </td>
                                    <td class="text-xs text-muted">{{ $h?->cluster ?? '—' }}</td>
                                    <td class="text-xs">@if ($m['mounted']) <span class="text-ok">✓</span> @else <span class="text-crit">✗ {{ __('no') }}</span> @endif</td>
                                    <td class="text-xs">@if ($m['accessible']) <span class="text-ok">✓</span> @else <span class="text-crit">✗ {{ __('no') }}</span> @endif</td>
                                    <td class="text-xs text-muted">{{ $m['mode'] }}</td>
                                    <td class="text-xs text-right tabular-nums">{{ $h ? $vms->where('host_moid', $h->moid)->count() : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Paths: multipathing of the backing LUNs per host --}}
            @if ($extents)
                <section x-show="tab === 'paths'" x-cloak>
                    <div class="card overflow-x-auto">
                        <div class="card-h">
                            <h2 class="card-t">{{ __('LUN paths per host') }}</h2>
                            @if ($deadPaths) <span class="badge badge-crit">{{ $deadPaths }} {{ __('dead') }}</span> @endif
                        </div>
                        <table class="table">
                            <thead><tr><th>{{ __('Host') }}</th><th>{{ __('Device') }}</th><th>{{ __('Array') }}</th><th>{{ __('Policy') }}</th><th class="text-right">{{ __('Active / paths') }}</th><th class="text-right">{{ __('Dead') }}</th></tr></thead>
                            <tbody>
                                @foreach ($paths as $l)
                                    <tr>
                                        <td class="whitespace-nowrap font-medium">{{ $l['host']?->name }}</td>
                                        <td class="font-mono text-xs">{{ $l['canonical'] }}</td>
                                        <td class="text-xs">{{ $l['vendor'] }} {{ $l['model'] }}</td>
                                        <td class="text-xs text-muted">{{ $l['policy'] }}</td>
                                        <td @class(['text-right tabular-nums text-xs', 'text-warn' => ! $l['local'] && $l['paths'] < 2])>{{ $l['active'] }}/{{ $l['paths'] }}</td>
                                        <td @class(['text-right tabular-nums text-xs', 'text-crit font-semibold' => $l['dead']])>{{ $l['dead'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if ($paths->isEmpty()) <p class="px-3 py-4 text-center text-sm text-muted">{{ __('No path information for this datastore.') }}</p> @endif
                    </div>
                </section>
            @endif

            {{-- Virtual machines with files on this datastore --}}
            <section x-show="tab === 'vms'" x-cloak>
                <div class="card">
                    <div class="card-h">
                        <h2 class="card-t">{{ __('Virtual machines') }} <span class="text-xs font-normal text-muted"><span data-count-for="ds-vms">{{ $vms->count() }}</span> · <span class="text-ok">{{ $vmOn }}</span> {{ __('running') }}</span></h2>
                        <input type="search" data-filter="ds-vms" class="input py-0.5 text-xs w-40" placeholder="{{ __('Filter…') }}" autocomplete="off">
                    </div>
                    <table class="table table-sticky table-sticky-under-title" id="ds-vms">
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th><th>{{ __('Power') }}</th><th>{{ __('Host') }}</th>
                                <th class="text-right" title="{{ __('Virtual disks on this datastore') }}">{{ __('Disks here') }}</th>
                                <th class="text-right" title="{{ __('Capacity of the virtual disks on this datastore') }}">{{ __('Size here') }}</th>
                                <th class="text-right">{{ __('VM used') }}</th>
                                <th class="text-right">{{ __('Snap.') }}</th>
                                <th>{{ __('Guest OS') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($vms as $vm)
                                <tr>
                                    <td class="whitespace-nowrap" data-sort="{{ $vm->name }}">
                                        <a href="{{ route('vms.show', $vm->ref()) }}" class="inline-flex items-center gap-1.5 font-medium hover:text-accent">
                                            @include('partials.dot', ['level' => Fmt::vsLevel($vm->overall_status)])
                                            {{ $vm->name }}
                                        </a>
                                        @if ($vm->template) <span class="badge ml-1">{{ __('template') }}</span> @endif
                                        @unless ($vm->ds_home) <span class="badge ml-1" title="{{ __('The VM configuration (.vmx) is on another datastore') }}">{{ __('disk only') }}</span> @endunless
                                    </td>
                                    <td class="text-xs"><span @class(['text-ok' => $vm->power_state === 'poweredOn', 'text-muted' => $vm->power_state !== 'poweredOn'])>{{ Fmt::state($vm->power_state) }}</span></td>
                                    <td class="text-xs text-muted whitespace-nowrap">{{ $vm->host ?? '—' }}</td>
                                    <td class="text-xs text-right tabular-nums">{{ $vm->ds_disks }}</td>
                                    <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $vm->ds_bytes }}">{{ $vm->ds_bytes ? Fmt::bytes($vm->ds_bytes) : '—' }}</td>
                                    <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $vm->used_bytes }}">{{ Fmt::bytes($vm->used_bytes) }}</td>
                                    <td class="text-xs text-right tabular-nums" data-sort="{{ $vm->snapshot_count }}">@if ($vm->snapshot_count) <span class="text-warn">{{ $vm->snapshot_count }}</span> @endif</td>
                                    <td class="text-xs text-muted max-w-56 truncate" title="{{ $vm->guest_os }}">{{ $vm->guest_os }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        @if ($vms->isNotEmpty())
                            <tfoot>
                                <tr class="bg-surface-2/60 font-semibold">
                                    <td class="text-xs text-muted" colspan="3">{{ __('Total') }} · <span data-count-for="ds-vms">{{ $vms->count() }}</span> VMs</td>
                                    <td class="text-xs text-right tabular-nums" data-sum>{{ $vms->sum('ds_disks') }}</td>
                                    <td class="text-xs text-right tabular-nums" data-sum="bytes">{{ Fmt::bytes($vms->sum('ds_bytes')) }}</td>
                                    <td class="text-xs text-right tabular-nums" data-sum="bytes">{{ Fmt::bytes($vms->sum('used_bytes')) }}</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                    @if ($vms->isEmpty()) <p class="px-3 py-4 text-center text-sm text-muted">{{ __('No virtual machines on this datastore.') }}</p> @endif
                </div>
            </section>
        </div>
    </div>
@endsection

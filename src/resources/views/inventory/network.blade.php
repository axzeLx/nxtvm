@extends('layouts.app', ['title' => $network->name, 'wide' => true])

{{--
    Network (port group) detail, same structure as the datastore and host pages: sticky title row + sidebar tabs
    (General · Hosts · Virtual machines). The open tab is kept in the URL hash.
--}}
@section('content')
    @php
        $e = fn ($v) => e((string) $v);
        $standard = $network->kind === 'standard';
        $vlanLabel = fn ($v) => $v === null || $v === '' ? '—' : ((string) $v === '0' ? '0 ('.__('none').')' : (string) $v);
        $vlanOdd = collect($perHost)->contains(fn ($pg) => (string) $pg['vlan'] !== (string) $majorVlan);
        $nicsOff = $vms->contains(fn ($vm) => collect($vm->data['nics'] ?? [])->where('network', $network->name)->contains(fn ($n) => ! ($n['connected'] ?? false)));
        $vmOn = $vms->where('power_state', 'poweredOn')->count();

        // [label, icon, count, attention flag]
        $tabs = [
            'general' => [__('General'), 'info', null, $network->vlan === 'mixed' ? 'warn' : null],
            'hosts' => [__('Hosts'), 'server', $hosts->count(), $vlanOdd ? 'warn' : null],
            'vms' => [__('Virtual machines'), 'vm', $vms->count(), $nicsOff ? 'warn' : null],
        ];
    @endphp

    {{-- title row stays under the fixed header --}}
    <div class="sticky top-[86px] z-30 -mx-4 -mt-4 mb-3 px-4 py-2.5 bg-bg border-b border-line flex flex-wrap items-center gap-2">
        <h1 class="text-base font-semibold"><span class="font-normal text-muted">{{ __('Network') }}:</span> {{ $network->name }}</h1>
        <span class="badge">{{ $standard ? __('standard') : __('distributed') }}</span>
        @if ($network->vlan !== null)
            <span @class(['badge', 'badge-warn' => $network->vlan === 'mixed'])>VLAN {{ $network->vlan }}</span>
        @endif
        <span class="ml-auto text-xs text-muted">{{ $vcenter->name }} · {{ __('collected') }} {{ Fmt::ago($vcenter->last_success_at) }}</span>
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

            {{-- General --}}
            <section x-show="tab === 'general'" class="grid gap-3 xl:grid-cols-2 items-start">
            @include('inventory.kv', ['title' => __('Network'), 'rows' => [
                __('Name') => $e($network->name),
                __('Type') => $standard ? __('Standard port group') : __('Distributed port group'),
                __('Switch') => $e($network->switch === 'mixed' ? __('differs per host') : $network->switch),
                'VLAN' => $network->vlan === 'mixed' ? '<span class="text-warn">'.__('differs per host').'</span>' : $e($vlanLabel($network->vlan)),
                __('Hosts') => $e($network->host_count),
                'VMs' => $e($vms->count()),
                __('Running VMs') => $e($vmOn),
                'vCenter' => $e($vcenter->name),
            ]])
            </section>

            {{-- Hosts --}}
            <section x-show="tab === 'hosts'" x-cloak>
            {{-- hosts carrying the port group; standard port groups have their own vSwitch / VLAN on every host --}}
            <div class="card overflow-x-auto">
                <div class="card-h"><h2 class="card-t">{{ __('Hosts') }} <span class="text-xs font-normal text-muted">{{ $hosts->count() }}</span></h2></div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Host') }}</th>
                            <th>{{ __('Cluster') }}</th>
                            @if ($standard) <th>vSwitch</th><th class="text-right">VLAN</th> @endif
                            <th class="text-right">VMs</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $vmsPerHost = $vms->countBy('host_moid'); @endphp
                        @foreach ($hosts as $h)
                            @php $pg = $perHost[$h->moid] ?? null; @endphp
                            <tr>
                                <td class="whitespace-nowrap font-medium">
                                    <a href="{{ route('hosts.show', $h->ref()) }}" class="inline-flex items-center gap-1.5 hover:text-accent">
                                        @include('partials.dot', ['level' => Fmt::vsLevel($h->overall_status)])
                                        {{ $h->name }}
                                    </a>
                                </td>
                                <td class="text-xs text-muted">{{ $h->cluster ?? __('standalone') }}</td>
                                @if ($standard)
                                    <td class="text-xs">{{ $pg['vswitch'] ?? '—' }}</td>
                                    <td @class(['text-xs text-right tabular-nums', 'text-warn font-semibold' => $pg && (string) $pg['vlan'] !== (string) $majorVlan])
                                        @if ($pg && (string) $pg['vlan'] !== (string) $majorVlan) title="{{ __('different VLAN than on the other hosts') }}" @endif>{{ $pg ? $vlanLabel($pg['vlan']) : '—' }}</td>
                                @endif
                                <td class="text-xs text-right tabular-nums">{{ $vmsPerHost[$h->moid] ?? 0 }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($hosts->isEmpty()) <p class="px-3 py-4 text-center text-sm text-muted">{{ __('No hosts.') }}</p> @endif
            </div>
            </section>

            {{-- Virtual machines --}}
            <section x-show="tab === 'vms'" x-cloak>
            {{-- VMs with a network adapter on this port group --}}
            <div class="card">
                <div class="card-h">
                    <h2 class="card-t">{{ __('Virtual machines') }} <span class="text-xs font-normal text-muted"><span data-count-for="n-vms">{{ $vms->count() }}</span> · <span class="text-ok">{{ $vmOn }}</span> {{ __('running') }}</span></h2>
                    @if ($vms->isNotEmpty())
                        <div class="flex items-center gap-2">
                            <input type="search" data-filter="n-vms" class="input py-0.5 text-xs w-48" placeholder="{{ __('Filter…') }}" autocomplete="off">
                            @include('partials.export-button', ['table' => 'n-vms', 'name' => Str::slug('network-'.$network->name.'-vms')])
                        </div>
                    @endif
                </div>
                @if ($vms->isNotEmpty())
                    <div>
                        <table class="table table-sticky table-sticky-under-title" id="n-vms">
                            <thead>
                                <tr>
                                    <th>VM</th>
                                    <th>{{ __('Power') }}</th>
                                    <th>{{ __('Guest OS') }}</th>
                                    <th>IP</th>
                                    <th>{{ __('Adapter') }}</th>
                                    <th>MAC</th>
                                    <th>{{ __('Connected') }}</th>
                                    <th>{{ __('Host') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($vms as $vm)
                                    {{-- one row per VM; several adapters on this network are listed in the same cells --}}
                                    @php $nics = collect($vm->data['nics'] ?? [])->where('network', $network->name)->values(); @endphp
                                    <tr>
                                        <td class="whitespace-nowrap font-medium">
                                            <a href="{{ route('vms.show', $vm->ref()) }}" class="hover:text-accent">{{ $vm->name }}</a>
                                            @if ($vm->template) <span class="badge">{{ __('template') }}</span> @endif
                                        </td>
                                        <td class="text-xs" data-sort="{{ $vm->power_state }}" data-export="{{ Fmt::state($vm->power_state) }}">
                                            <span class="inline-flex items-center gap-1.5">@include('partials.power', ['state' => $vm->power_state, 'class' => 'size-3.5']) {{ Fmt::state($vm->power_state) }}</span>
                                        </td>
                                        <td class="text-xs whitespace-nowrap" title="{{ $vm->guest_os }}">{{ Fmt::osShort($vm->guest_os) }}</td>
                                        <td class="text-xs font-mono whitespace-nowrap">{{ $vm->ip }}</td>
                                        <td class="text-xs whitespace-nowrap" data-export="{{ $nics->map(fn ($n) => ($n['label'] ?? '').' · '.($n['type'] ?? ''))->implode(', ') }}">
                                            @foreach ($nics as $nic)
                                                <div @class(['text-warn' => str_starts_with($nic['type'] ?? '', 'E1000')])>{{ $nic['label'] ?? '' }} · {{ $nic['type'] ?? '' }}</div>
                                            @endforeach
                                        </td>
                                        <td class="text-xs font-mono text-muted" data-export="{{ $nics->pluck('mac')->implode(', ') }}">
                                            @foreach ($nics as $nic) <div>{{ $nic['mac'] ?? '' }}</div> @endforeach
                                        </td>
                                        <td class="text-xs" data-export="{{ $nics->map(fn ($n) => ($n['connected'] ?? false) ? __('yes') : __('no'))->implode(', ') }}">
                                            @foreach ($nics as $nic)
                                                <div>{!! ($nic['connected'] ?? false) ? '<span class="text-ok">✓</span>' : '<span class="text-crit" title="'.e(__('not connected')).'">✗</span>' !!}</div>
                                            @endforeach
                                        </td>
                                        <td class="text-xs whitespace-nowrap">
                                            @if ($vm->host_moid)
                                                <a href="{{ route('hosts.show', ['vc' => $vm->vcenter_id, 'moid' => $vm->host_moid]) }}" class="hover:text-accent">{{ $vm->host }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="px-3 py-4 text-center text-sm text-muted">{{ __('No VM is connected to this network.') }}</p>
                @endif
            </div>
            </section>
        </div>
    </div>
@endsection

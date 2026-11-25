@extends('layouts.app', ['title' => __('Components'), 'wide' => true])

@section('content')
    @include('inventory.toolbar', ['title' => __('Components')])
    @php
        $e = fn ($v) => e((string) $v);
        $onoff = fn ($b) => $b ? '<span class="text-ok">'.__('on').'</span>' : '<span class="text-warn">'.__('off').'</span>';
    @endphp

    @foreach ($shown as $vc)
        @php
            $a = $vc->about ?? []; $inv = $vc->inventory ?? []; $set = $inv['settings'] ?? [];
            $ap = $inv['appliance']['endpoints'] ?? [];
            $ver = ($ap['version']['ok'] ?? false) ? $ap['version']['data'] : null;
            $svcHealth = ($ap['health_services']['ok'] ?? false) ? $ap['health_services']['data'] : null;
        @endphp
        <div class="grid gap-3 lg:grid-cols-3 mb-3">
            @include('inventory.kv', ['title' => 'vCenter · '.$vc->name, 'rows' => [
                __('Product') => $e($a['full_name'] ?? null),
                __('Patch') => $ver ? $e(($ver['summary'] ?? '').' · '.substr($ver['install_time'] ?? '', 0, 10)) : null,
                __('Address') => '<span class="font-mono text-xs">'.$e($vc->host.($vc->ip && $vc->ip !== $vc->host ? ' · '.$vc->ip : '')).'</span>',
                'FQDN' => $e($set['VirtualCenter.FQDN'] ?? null),
                __('Instance UUID') => '<span class="font-mono text-[11px]">'.$e($a['instance_uuid'] ?? null).'</span>',
                __('Datacenters') => $e(collect($inv['datacenters'] ?? [])->pluck('name')->implode(', ')),
                __('Services health') => $svcHealth ? '<span class="text-'.Fmt::vsLevel($svcHealth).'">'.$e($svcHealth).'</span>' : null,
                __('Account') => $e($inv['user'] ?? $vc->username),
            ]])
            @include('inventory.kv', ['title' => __('vCenter settings'), 'rows' => [
                __('Event retention') => $e(($set['event.maxAgeEnabled'] ?? false) ? ($set['event.maxAge'] ?? '?').' '.__('days') : __('unlimited')),
                __('Task retention') => $e(($set['task.maxAgeEnabled'] ?? false) ? ($set['task.maxAge'] ?? '?').' '.__('days') : __('unlimited')),
                __('Mail server') => ($set['mail.smtp.server'] ?? '') !== '' ? $e($set['mail.smtp.server']) : '<span class="text-warn">'.__('not configured').'</span>',
                __('Mail sender') => $e($set['mail.sender'] ?? null),
                'SNMP' => ($set['snmp.receiver.1.enabled'] ?? false) ? $e($set['snmp.receiver.1.name'] ?? '') : __('off'),
                __('Log level') => $e($set['config.log.level'] ?? null),
                __('Collection') => $e(Fmt::ago($vc->last_success_at).' · '.round(($vc->collect_ms ?? 0) / 1000, 1).' s'),
            ]])
            <div class="card">
                <div class="card-h"><h2 class="card-t">{{ __('Appliance API') }}</h2></div>
                <p class="px-3 pt-2 text-[11px] text-muted">{{ __('What the account can read from the appliance management API. 403 = needs appliance privileges.') }}</p>
                <div class="px-3 py-2 flex flex-wrap gap-1">
                    @forelse ($ap as $name => $r)
                        <span @class(['badge', 'badge-ok' => $r['ok']]) title="{{ $r['ok'] ? '' : 'HTTP '.($r['status'] ?? '?') }}">{{ str_replace('_', ' ', $name) }}{{ $r['ok'] ? '' : ' · '.($r['status'] ?? '✗') }}</span>
                    @empty
                        <span class="text-xs text-muted">{{ $inv['appliance']['error'] ?? __('No data yet.') }}</span>
                    @endforelse
                </div>
                @if (! empty($inv['warnings']))
                    <div class="px-3 pb-2 text-xs text-warn space-y-0.5">@foreach ($inv['warnings'] as $w) <div>⚠ {{ $w }}</div> @endforeach</div>
                @endif
            </div>
        </div>
    @endforeach

    <div class="card overflow-x-auto mb-3">
        <div class="card-h"><h2 class="card-t">{{ __('Clusters') }} <span class="badge">{{ $clusters->count() }}</span></h2></div>
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('Cluster') }}</th>
                    @if ($vcenters->count() > 1) <th>vCenter</th> @endif
                    <th>{{ __('Datacenter') }}</th>
                    <th class="text-right">{{ __('Hosts') }}</th>
                    <th class="text-right">CPU</th>
                    <th class="text-right">RAM</th>
                    <th>HA</th>
                    <th>{{ __('Admission control') }}</th>
                    <th>{{ __('Host / VM monitoring') }}</th>
                    <th>DRS</th>
                    <th>EVC</th>
                    <th>vSAN</th>
                    <th>{{ __('ESXi builds') }}</th>
                    <th class="text-right">{{ __('Rules') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($clusters as $c)
                    @php
                        $d = $c->data; $ha = $d['ha']; $drs = $d['drs'];
                        $builds = ($hostsByCluster[$c->vcenter_id.'|'.$c->moid] ?? collect())->pluck('build')->unique()->filter();
                        $ac = ! $ha['admission_control'] ? null : match ($ha['admission_policy']) {
                            'ClusterFailoverResourcesAdmissionControlPolicy' => __(':cpu% CPU / :mem% RAM reserved', ['cpu' => $ha['cpu_failover_pct'], 'mem' => $ha['mem_failover_pct']]),
                            'ClusterFailoverLevelAdmissionControlPolicy' => __(':n host failure(s) tolerated', ['n' => $ha['failover_level']]),
                            'ClusterFailoverHostAdmissionControlPolicy' => __('dedicated failover hosts'),
                            default => $ha['admission_policy'],
                        };
                    @endphp
                    <tr>
                        <td class="font-medium whitespace-nowrap">
                            <span class="inline-flex items-center gap-1.5">@include('partials.dot', ['level' => Fmt::vsLevel($d['overall_status'] ?? null)]) {{ $c->name }}</span>
                        </td>
                        @if ($vcenters->count() > 1) @include('partials.vcname', ['row' => $c]) @endif
                        <td class="text-xs">{{ $c->datacenter }}</td>
                        <td class="text-xs text-right tabular-nums">{{ $d['num_effective_hosts'] ?? '?' }}/{{ $c->num_hosts }}</td>
                        <td class="text-xs text-right tabular-nums whitespace-nowrap">{{ round(($c->total_cpu_mhz ?? 0) / 1000) }} GHz</td>
                        <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $c->total_mem_bytes }}">{{ Fmt::bytes($c->total_mem_bytes) }}</td>
                        <td class="text-xs">{!! $onoff($ha['enabled']) !!}</td>
                        <td class="text-xs">{{ $ha['enabled'] ? ($ac ?? __('off')) : '—' }}</td>
                        <td class="text-xs">{{ $ha['enabled'] ? $ha['host_monitoring'].' / '.str_replace('vmMonitoring', '', $ha['vm_monitoring'] ?? '') : '—' }}</td>
                        <td class="text-xs">{!! $onoff($drs['enabled']) !!}@if ($drs['enabled']) <span class="text-muted">· {{ $drs['behavior'] }} · {{ $drs['migration_threshold'] }}</span>@endif</td>
                        <td class="text-xs">{{ $d['evc_mode'] ?? __('off') }}</td>
                        <td class="text-xs">{{ $d['vsan'] ? __('on') : __('off') }}</td>
                        <td @class(['text-xs', 'text-warn' => $builds->count() > 1])>{{ $builds->implode(', ') }}</td>
                        <td class="text-xs text-right tabular-nums">{{ count($d['rules'] ?? []) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($clusters->isEmpty()) <p class="px-3 py-6 text-center text-sm text-muted">{{ __('No data yet.') }}</p> @endif
    </div>

    @php $rules = $clusters->flatMap(fn ($c) => collect($c->data['rules'] ?? [])->map(fn ($r) => $r + ['cluster' => $c->name])); @endphp
    @if ($rules->isNotEmpty())
        <div class="card overflow-x-auto mb-3">
            <div class="card-h"><h2 class="card-t">{{ __('DRS rules') }} <span class="badge">{{ $rules->count() }}</span></h2></div>
            <table class="table">
                <thead><tr><th>{{ __('Cluster') }}</th><th>{{ __('Rule') }}</th><th>{{ __('Type') }}</th><th>{{ __('Enabled') }}</th><th>{{ __('Mandatory') }}</th><th>{{ __('Members') }}</th></tr></thead>
                <tbody>
                    @foreach ($rules as $r)
                        <tr>
                            <td class="text-xs">{{ $r['cluster'] }}</td>
                            <td class="text-xs font-medium">{{ $r['name'] }}</td>
                            <td class="text-xs">{{ $r['type'] }}</td>
                            <td class="text-xs">{!! $onoff($r['enabled']) !!}</td>
                            <td class="text-xs">{{ $r['mandatory'] ? __('yes') : __('no') }}</td>
                            <td class="text-xs text-muted">{{ $r['vm_group'] ? $r['vm_group'].' → '.$r['host_group'] : count($r['vms']).' VMs' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="card overflow-x-auto">
        <div class="card-h"><h2 class="card-t">{{ __('Triggered alarms') }} <span class="badge">{{ $alarms->count() }}</span></h2>
            <span class="text-[11px] text-muted">{{ __('As reported by vCenter at collection time.') }}</span></div>
        <table class="table">
            <thead><tr><th>{{ __('Status') }}</th><th>{{ __('Alarm') }}</th><th>{{ __('Object') }}</th><th>{{ __('Type') }}</th><th>{{ __('Since') }}</th><th>{{ __('Ack.') }}</th></tr></thead>
            <tbody>
                @foreach ($alarms as $al)
                    <tr>
                        <td><span class="badge badge-{{ Fmt::vsLevel($al->status) }}">{{ $al->status }}</span></td>
                        <td class="text-xs">{{ $al->alarm }}</td>
                        <td class="text-xs font-medium">{{ $al->entity ?? ($al->entity_type === 'Folder' ? 'vCenter' : '—') }}</td>
                        <td class="text-xs text-muted">{{ $al->entity_type }}</td>
                        <td class="text-xs tabular-nums whitespace-nowrap">{{ Fmt::time($al->triggered_at) }}</td>
                        <td class="text-xs">{{ $al->acknowledged ? __('yes') : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($alarms->isEmpty()) <p class="px-3 py-4 text-center text-sm text-muted">{{ __('No triggered alarms.') }}</p> @endif
    </div>
@endsection

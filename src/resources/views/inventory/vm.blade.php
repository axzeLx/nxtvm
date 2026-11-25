@extends('layouts.app', ['title' => $vm->name, 'wide' => true])

@section('content')
    @php
        $d = $vm->data; $e = fn ($v) => e((string) $v);
        $lim = fn ($v, $unit) => $v === null || $v < 0 ? __('unlimited') : $v.' '.$unit;
    @endphp
    <div class="flex flex-wrap items-center gap-2 mb-3">
        <h1 class="text-base font-semibold"><span class="font-normal text-muted">VM:</span> {{ $vm->name }}</h1>
        @include('partials.power', ['state' => $vm->power_state])
        @if ($vm->guest_os || $vm->ip)
            <span class="text-sm text-muted">
                <span title="{{ $vm->guest_os }}">{{ Fmt::osShort($vm->guest_os) }}</span>@if ($vm->guest_os && $vm->ip) – @endif<span class="font-mono">{{ $vm->ip }}</span>
            </span>
        @endif
        {{-- vSphere overall status (alarm based health) --}}
        @include('partials.dot', ['level' => Fmt::vsLevel($vm->overall_status), 'title' => __('vSphere status').': '.($vm->overall_status ?? '—')])
        @if ($vm->template) <span class="badge">{{ __('template') }}</span> @endif
        @if ($d['consolidation_needed'] ?? false) <span class="badge badge-crit">{{ __('consolidation needed') }}</span> @endif
        @if (($d['connection_state'] ?? 'connected') !== 'connected') <span class="badge badge-crit">{{ $d['connection_state'] }}</span> @endif
        <span class="ml-auto text-xs text-muted">{{ $vcenter->name }} · {{ __('collected') }} {{ Fmt::ago($vcenter->last_success_at) }}</span>
    </div>

    <div class="grid gap-3 lg:grid-cols-3">
        @include('inventory.kv', ['title' => __('General'), 'rows' => [
            __('Host') => $vm->host_moid ? '<a class="hover:text-accent" href="'.route('hosts.show', ['vc' => $vm->vcenter_id, 'moid' => $vm->host_moid]).'">'.$e($vm->host).'</a>' : null,
            __('Cluster') => $e($vm->cluster),
            __('Folder') => $e($vm->folder),
            __('Guest OS') => $e($d['guest_os'] ?? null).(($d['guest_os_config'] ?? null) && $d['guest_os_config'] !== ($d['guest_os'] ?? null) ? ' <span class="text-warn" title="'.__('Configured OS differs').'">('.$e($d['guest_os_config']).')</span>' : ''),
            __('Hostname') => $e($d['hostname'] ?? null),
            'IP' => '<span class="font-mono text-xs">'.$e(implode(', ', $d['ips'] ?? [])).'</span>',
            __('Created') => $e(substr($d['created'] ?? '', 0, 10)),
            __('Boot time') => $e(($d['boot_time'] ?? null) ? Fmt::time(\Carbon\Carbon::parse($d['boot_time'])) : null),
            __('Notes') => $e($d['annotation'] ?? null),
        ]])
        @include('inventory.kv', ['title' => __('Hardware'), 'rows' => [
            'vCPU' => $e($vm->vcpu.' ('.($d['cores_per_socket'] ?? '?').' '.__('cores/socket').')'),
            __('Memory') => $e(Fmt::mb($vm->mem_mb)),
            __('HW version') => $e($vm->hw_version),
            __('Firmware') => $e(($d['firmware'] ?? '').(($d['secure_boot'] ?? false) ? ' · Secure Boot' : '')),
            __('Hot add') => $e('CPU '.(($d['cpu_hot_add'] ?? false) ? __('on') : __('off')).' · RAM '.(($d['mem_hot_add'] ?? false) ? __('on') : __('off'))),
            __('CPU res. / limit') => $e(($d['cpu_reservation'] ?? 0).' MHz / '.$lim($d['cpu_limit'] ?? null, 'MHz')),
            __('RAM res. / limit') => $e(($d['mem_reservation'] ?? 0).' MB / '.$lim($d['mem_limit'] ?? null, 'MB')),
            'VMware Tools' => $e(Fmt::state($vm->tools_running).' · '.Fmt::state($vm->tools_status).' ('.($d['tools_version'] ?? '?').')'),
            __('Config file') => '<span class="font-mono text-xs">'.$e($d['vmx'] ?? null).'</span>',
        ]])
        <div class="space-y-3">
            @include('inventory.kv', ['title' => __('Storage'), 'rows' => [
                __('Provisioned') => $e(Fmt::bytes($vm->provisioned_bytes)),
                __('Used') => $e(Fmt::bytes($vm->used_bytes)),
                __('Snapshots') => $vm->snapshot_count ? '<span class="text-warn">'.$e($vm->snapshot_count.' · '.Fmt::bytes($vm->snapshot_bytes)).'</span>' : '0',
                __('Datastores') => collect($d['datastores'] ?? [])->map(fn ($m) => '<a class="hover:text-accent" href="'.route('datastores.show', ['vc' => $vm->vcenter_id, 'moid' => $m]).'">'.e($dsNames[$m] ?? $m).'</a>')->implode(', '),
            ]])
            @if (! empty($d['guest_disks']))
                <div class="card overflow-x-auto">
                    <div class="card-h"><h2 class="card-t">{{ __('Guest file systems') }}</h2></div>
                    <table class="table">
                        <thead><tr><th>{{ __('Path') }}</th><th class="text-right">{{ __('Size') }}</th><th>{{ __('Used') }}</th></tr></thead>
                        <tbody>
                            @foreach ($d['guest_disks'] as $g)
                                <tr>
                                    <td class="font-mono text-xs">{{ $g['path'] }}</td>
                                    <td class="text-xs text-right tabular-nums">{{ Fmt::bytes($g['capacity']) }}</td>
                                    <td>@include('partials.bar', ['pct' => Fmt::pct($g['capacity'] - $g['free'], $g['capacity']), 'warn' => 85, 'crit' => 95])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="grid gap-3 lg:grid-cols-2 mt-3">
        <div class="card overflow-x-auto">
            <div class="card-h"><h2 class="card-t">{{ __('Disks') }}</h2></div>
            <table class="table">
                <thead><tr><th>{{ __('Disk') }}</th><th class="text-right">{{ __('Size') }}</th><th>{{ __('Type') }}</th><th>{{ __('Mode') }}</th><th>{{ __('File') }}</th></tr></thead>
                <tbody>
                    @foreach ($d['disks'] ?? [] as $k)
                        <tr>
                            <td class="text-xs whitespace-nowrap">{{ $k['label'] }}</td>
                            <td class="text-xs text-right tabular-nums">{{ Fmt::bytes($k['capacity_bytes']) }}</td>
                            <td class="text-xs">{{ $k['rdm'] ? 'RDM '.$k['rdm'] : ($k['thin'] ? 'thin' : ($k['eager'] ? 'thick eager' : 'thick lazy')) }}</td>
                            <td class="text-xs text-muted">{{ $k['mode'] }}{{ ($k['sharing'] ?? 'sharingNone') !== 'sharingNone' ? ' · '.$k['sharing'] : '' }}</td>
                            <td class="font-mono text-[11px] text-muted break-all">{{ $k['file'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card overflow-x-auto">
            <div class="card-h"><h2 class="card-t">{{ __('Network adapters & devices') }}</h2></div>
            <table class="table">
                <thead><tr><th>{{ __('Device') }}</th><th>{{ __('Type') }}</th><th>{{ __('Network') }}</th><th>MAC</th><th>{{ __('Connected') }}</th></tr></thead>
                <tbody>
                    @foreach ($d['nics'] ?? [] as $n)
                        <tr>
                            <td class="text-xs whitespace-nowrap">{{ $n['label'] }}</td>
                            <td @class(['text-xs', 'text-warn' => str_starts_with($n['type'], 'E1000')])>{{ $n['type'] }}</td>
                            <td class="text-xs">
                                @if (isset($netMoids[$n['network']]))
                                    <a href="{{ route('networks.show', ['vc' => $vm->vcenter_id, 'moid' => $netMoids[$n['network']]]) }}" class="hover:text-accent">{{ $n['network'] }}</a>
                                @else
                                    {{ $n['network'] }}
                                @endif
                            </td>
                            <td class="font-mono text-xs text-muted">{{ $n['mac'] }}</td>
                            <td class="text-xs">{!! $n['connected'] ? '<span class="text-ok">✓</span>' : '<span class="text-muted">—</span>' !!}</td>
                        </tr>
                    @endforeach
                    @foreach ($d['controllers'] ?? [] as $c)
                        <tr><td class="text-xs">{{ __('Controller') }} {{ $c['bus'] }}</td><td class="text-xs">{{ $c['type'] }}</td><td class="text-xs text-muted" colspan="3">{{ $c['sharing'] && $c['sharing'] !== 'noSharing' ? $c['sharing'] : '' }}</td></tr>
                    @endforeach
                    @foreach ($d['cdroms'] ?? [] as $c)
                        <tr><td class="text-xs">{{ $c['label'] }}</td><td class="text-xs">CD/DVD</td><td class="text-xs text-muted" colspan="2">{{ $c['iso'] }}</td>
                            <td class="text-xs">{!! $c['connected'] ? '<span class="text-warn">'.__('connected').'</span>' : '<span class="text-muted">—</span>' !!}</td></tr>
                    @endforeach
                    @foreach ($d['other_devices'] ?? [] as $o)
                        <tr><td class="text-xs">{{ $o['label'] }}</td><td class="text-xs">{{ $o['type'] }}</td><td colspan="2"></td>
                            <td class="text-xs">{!! $o['connected'] ? '<span class="text-warn">'.__('connected').'</span>' : '<span class="text-muted">—</span>' !!}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if (! empty($d['snapshots']))
            <div class="card overflow-x-auto lg:col-span-2">
                <div class="card-h"><h2 class="card-t">{{ __('Snapshots') }}</h2><span class="text-xs text-muted">{{ __('Total size') }} {{ Fmt::bytes($vm->snapshot_bytes) }}</span></div>
                <table class="table">
                    <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Created') }}</th><th class="text-right">{{ __('Age') }}</th><th>{{ __('Memory') }}</th><th>{{ __('Description') }}</th></tr></thead>
                    <tbody>
                        @foreach ($d['snapshots'] as $s)
                            @php $age = $s['created'] ? (int) \Carbon\Carbon::parse($s['created'])->diffInDays(now()) : null; @endphp
                            <tr>
                                <td class="text-xs font-medium">{{ $s['name'] }}</td>
                                <td class="text-xs tabular-nums whitespace-nowrap">{{ Fmt::time($s['created'] ? \Carbon\Carbon::parse($s['created']) : null) }}</td>
                                <td @class(['text-xs text-right tabular-nums', 'text-warn' => $age > 3 && $age <= 14, 'text-crit' => $age > 14])>{{ $age }} d</td>
                                <td class="text-xs">{{ $s['memory'] ? __('yes') : __('no') }}</td>
                                <td class="text-xs text-muted whitespace-pre-line">{{ $s['description'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection

@extends('layouts.app', ['title' => __('VMs'), 'wide' => true])

@section('content')
    @include('inventory.toolbar', ['title' => __('Virtual machines'), 'count' => $vms->count(), 'table' => 't-vms'])
    @php $multi = $vcenters->count() > 1; @endphp
    @php
        $opts = fn ($col) => $vms->pluck($col)->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
        $hw = $vms->pluck('hw_version')->filter()->map(fn ($v) => str_replace('vmx-', '', $v))->unique()->sort(SORT_NATURAL)->values();
        $powers = $vms->pluck('power_state')->filter()->map(fn ($p) => Fmt::state($p))->unique()->sort()->values();
        $gb = fn ($bytes) => $bytes === null ? '' : round($bytes / 1073741824, 1);
    @endphp
    <div class="flex items-center gap-2 -mt-2 mb-2">
        <p class="text-[11px] text-muted">{{ __('Column filters combine with the quick filter. Numbers: 8, >4, <=16, 4-8 (RAM / disk in GB).') }}</p>
        <button type="button" class="btn btn-sm ml-auto" data-clear-filters="t-vms">{{ __('Clear filters') }}</button>
    </div>
    <div class="card table-scroll">
        <table class="table table-sticky" id="t-vms">
            <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    @if ($multi) <th>vCenter</th> @endif
                    <th>{{ __('Power') }}</th>
                    <th>{{ __('Host') }}</th>
                    <th>{{ __('Cluster') }}</th>
                    <th>{{ __('Folder') }}</th>
                    <th>{{ __('Guest OS') }}</th>
                    <th class="text-right">vCPU</th>
                    <th class="text-right">RAM</th>
                    <th class="text-right">{{ __('Prov.') }}</th>
                    <th class="text-right">{{ __('Used') }}</th>
                    <th>IP</th>
                    <th>Tools</th>
                    <th>HW</th>
                    <th class="text-right">{{ __('Snap.') }}</th>
                </tr>
                <tr data-filters>
                    <th><input type="search" data-cf="text" class="input w-32" placeholder="{{ __('name') }}" autocomplete="off"></th>
                    @if ($multi)
                        <th><select data-cf="select" class="input"><option value="">{{ __('all') }}</option>@foreach ($vcenters as $v)<option>{{ $v->name }}</option>@endforeach</select></th>
                    @endif
                    <th>@foreach ($powers as $p)<label class="cf-check mr-1.5"><input type="checkbox" data-cf="check" value="{{ $p }}">{{ $p }}</label>@endforeach</th>
                    <th><select data-cf="select" class="input max-w-36"><option value="">{{ __('all') }}</option>@foreach ($opts('host') as $o)<option>{{ $o }}</option>@endforeach</select></th>
                    <th><select data-cf="select" class="input max-w-28"><option value="">{{ __('all') }}</option>@foreach ($opts('cluster') as $o)<option>{{ $o }}</option>@endforeach</select></th>
                    <th><input type="search" data-cf="text" class="input w-24" placeholder="{{ __('folder') }}" autocomplete="off"></th>
                    <th><select data-cf="select" class="input max-w-44"><option value="">{{ __('all') }}</option>@foreach ($opts('guest_os') as $o)<option>{{ $o }}</option>@endforeach</select></th>
                    <th><input type="search" data-cf="num" class="input w-12 text-right" placeholder="#" autocomplete="off"></th>
                    <th><input type="search" data-cf="num" class="input w-14 text-right" placeholder="GB" autocomplete="off"></th>
                    <th><input type="search" data-cf="num" class="input w-14 text-right" placeholder="GB" autocomplete="off"></th>
                    <th><input type="search" data-cf="num" class="input w-14 text-right" placeholder="GB" autocomplete="off"></th>
                    <th><input type="search" data-cf="text" class="input w-28 font-mono" placeholder="10.…" autocomplete="off"></th>
                    <th>
                        <label class="cf-check mr-1.5"><input type="checkbox" data-cf="check" value="run">{{ __('run') }}</label>
                        <label class="cf-check"><input type="checkbox" data-cf="check" value="not">{{ __('not run') }}</label>
                    </th>
                    <th><select data-cf="select" class="input w-14"><option value="">{{ __('all') }}</option>@foreach ($hw as $o)<option>{{ $o }}</option>@endforeach</select></th>
                    <th class="text-right"><label class="cf-check"><input type="checkbox" data-cf="check" value="yes">{{ __('has') }}</label></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($vms as $vm)
                    @php
                        $toolsBad = $vm->power_state === 'poweredOn' && $vm->tools_running !== 'guestToolsRunning';
                        $search = 'power:'.Fmt::state($vm->power_state).($vm->template ? ' template' : '').($vm->snapshot_count ? ' snapshot' : '').($toolsBad ? ' tools:not' : '');
                    @endphp
                    <tr data-search="{{ $search }}">
                        <td class="whitespace-nowrap" data-sort="{{ $vm->name }}" data-f="{{ $vm->name }}">
                            <a href="{{ route('vms.show', $vm->ref()) }}" class="inline-flex items-center gap-1.5 font-medium hover:text-accent">
                                @include('partials.dot', ['level' => Fmt::vsLevel($vm->overall_status), 'title' => $vm->overall_status])
                                {{ $vm->name }}
                            </a>
                            @if ($vm->template) <span class="badge ml-1">{{ __('template') }}</span> @endif
                        </td>
                        @if ($multi) @include('partials.vcname', ['row' => $vm]) @endif
                        <td class="text-xs" data-f="{{ Fmt::state($vm->power_state) }}">
                            <span @class(['text-ok' => $vm->power_state === 'poweredOn', 'text-muted' => $vm->power_state !== 'poweredOn'])>{{ Fmt::state($vm->power_state) }}</span>
                        </td>
                        <td class="text-xs whitespace-nowrap" data-f="{{ $vm->host }}">{{ $vm->host ?? '—' }}</td>
                        <td class="text-xs whitespace-nowrap" data-f="{{ $vm->cluster }}">{{ $vm->cluster ?? '—' }}</td>
                        <td class="text-xs text-muted max-w-40 truncate" title="{{ $vm->folder }}">{{ $vm->folder ?? '' }}</td>
                        <td class="text-xs max-w-48 truncate" title="{{ $vm->guest_os }}" data-f="{{ $vm->guest_os }}">{{ $vm->guest_os ?? '—' }}</td>
                        <td class="text-xs text-right tabular-nums">{{ $vm->vcpu }}</td>
                        <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $vm->mem_mb }}" data-f="{{ $vm->mem_mb === null ? '' : round($vm->mem_mb / 1024, 2) }}">{{ Fmt::mb($vm->mem_mb) }}</td>
                        <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $vm->provisioned_bytes }}" data-f="{{ $gb($vm->provisioned_bytes) }}">{{ Fmt::bytes($vm->provisioned_bytes) }}</td>
                        <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $vm->used_bytes }}" data-f="{{ $gb($vm->used_bytes) }}">{{ Fmt::bytes($vm->used_bytes) }}</td>
                        <td class="text-xs font-mono whitespace-nowrap">{{ $vm->ip ?? '' }}</td>
                        <td class="text-xs whitespace-nowrap" data-f="{{ $vm->power_state !== 'poweredOn' ? 'off' : ($toolsBad ? 'not' : 'run') }}">
                            @if ($vm->power_state !== 'poweredOn') <span class="text-muted">—</span>
                            @else
                                <span @class(['text-ok' => ! $toolsBad, 'text-crit' => $toolsBad])>{{ Fmt::state($vm->tools_running) }}</span>
                                <span class="text-muted">· {{ Fmt::state($vm->tools_status) }}</span>
                            @endif
                        </td>
                        <td class="text-xs text-muted" data-sort="{{ (int) substr($vm->hw_version ?? '', 4) }}" data-f="{{ str_replace('vmx-', '', $vm->hw_version ?? '') }}">{{ str_replace('vmx-', '', $vm->hw_version ?? '') }}</td>
                        <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $vm->snapshot_bytes ?: $vm->snapshot_count }}" data-f="{{ $vm->snapshot_count ? 'yes' : 'no' }}">
                            @if ($vm->snapshot_count) <span class="text-warn">{{ $vm->snapshot_count }}</span> <span class="text-muted">{{ Fmt::bytes($vm->snapshot_bytes) }}</span> @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($vms->isEmpty()) <p class="px-3 py-6 text-center text-sm text-muted">{{ __('No data yet.') }}</p> @endif
    </div>
@endsection

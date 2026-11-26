@extends('layouts.app', ['title' => __('Datastores'), 'wide' => true])

@section('content')
    @include('inventory.toolbar', ['title' => __('Datastores'), 'count' => $datastores->reject(fn ($ds) => $ds->data['vmfs']['local'] ?? false)->count(), 'table' => 't-ds', 'localToggle' => 't-ds'])
    @php $multi = $vcenters->count() > 1; @endphp

    <div class="card overflow-x-auto mb-4">
        <table class="table" id="t-ds">
            <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    @if ($multi) <th>vCenter</th> @endif
                    <th>{{ __('Type') }}</th>
                    <th>{{ __('Backing') }}</th>
                    <th class="text-right">{{ __('Capacity') }}</th>
                    <th class="text-right">{{ __('Free') }}</th>
                    <th>{{ __('Used') }}</th>
                    <th class="text-right">{{ __('Provisioned') }}</th>
                    <th class="text-right" title="{{ __('Provisioned ÷ capacity; above 100 % the datastore is overcommitted') }}">{{ __('Provisioned %') }}</th>
                    <th class="text-right">{{ __('Hosts') }}</th>
                    <th class="text-right">VMs</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($datastores as $ds)
                    @php
                        $d = $ds->data;
                        $used = Fmt::pct($ds->capacity_bytes - $ds->free_bytes, $ds->capacity_bytes);
                        $over = Fmt::pct($ds->provisioned_bytes, $ds->capacity_bytes);
                        $local = $d['vmfs']['local'] ?? false;
                    @endphp
                    {{-- local datastores start hidden ("Show local datastores" in the toolbar) --}}
                    <tr @if ($local) data-local style="display: none" @endif>
                        <td class="whitespace-nowrap font-medium" data-sort="{{ $ds->name }}">
                            <a href="{{ route('datastores.show', $ds->ref()) }}" class="inline-flex items-center gap-1.5 hover:text-accent">
                                @include('partials.dot', ['level' => $ds->accessible ? Fmt::vsLevel($d['overall_status'] ?? null) : 'crit'])
                                {{ $ds->name }}
                            </a>
                            @if ($local) <span class="badge">{{ __('local') }}</span> @endif
                            @if ($d['cluster'] ?? null) <span class="badge" title="{{ __('Datastore cluster') }}">{{ $d['cluster'] }}</span> @endif
                            @if (($d['maintenance'] ?? 'normal') !== 'normal') <span class="badge badge-warn">{{ $d['maintenance'] }}</span> @endif
                        </td>
                        @if ($multi) @include('partials.vcname', ['row' => $ds]) @endif
                        <td class="text-xs">{{ $ds->type }}{{ ($d['vmfs']['version'] ?? null) ? ' '.$d['vmfs']['version'] : '' }}{{ ($d['vmfs']['ssd'] ?? false) ? ' · SSD' : '' }}</td>
                        <td class="text-xs font-mono text-muted max-w-56 truncate" title="{{ implode(', ', $d['vmfs']['extents'] ?? []) }}">
                            @if ($d['nas'] ?? null) {{ $d['nas']['server'] }}:{{ $d['nas']['path'] }} @else {{ implode(', ', $d['vmfs']['extents'] ?? []) }} @endif
                        </td>
                        <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $ds->capacity_bytes }}">{{ Fmt::bytes($ds->capacity_bytes) }}</td>
                        <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $ds->free_bytes }}">{{ Fmt::bytes($ds->free_bytes) }}</td>
                        <td data-sort="{{ $used }}">@include('partials.bar', ['pct' => $used])</td>
                        <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $ds->provisioned_bytes }}">{{ Fmt::bytes($ds->provisioned_bytes) }}</td>
                        <td @class(['text-xs text-right tabular-nums', 'text-info' => $over > 100 && $over < 150, 'text-warn' => $over >= 150 && $over < 200, 'text-crit' => $over >= 200]) data-sort="{{ $over }}">{{ $over !== null ? $over.'%' : '—' }}</td>
                        <td class="text-xs text-right tabular-nums">{{ $ds->host_count }}</td>
                        <td class="text-xs text-right tabular-nums">{{ $ds->vm_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h2 class="text-sm font-semibold mb-1">{{ __('Mounts per host') }}</h2>
    <p class="text-xs text-muted mb-2">
        <span class="text-ok">✓</span> {{ __('mounted') }} ·
        <span class="text-crit">!</span> {{ __('mounted but not accessible') }} ·
        <span class="text-warn">✗</span> {{ __('not mounted on this host (shared datastores only)') }}
    </p>
    @include('inventory.matrix', [
        'id' => 'mx-ds',
        'heads' => [__('Datastore'), __('Type'), __('Capacity')],
        'left' => fn ($ds) => [
            '<td class="whitespace-nowrap font-medium">'.e($ds->name).(($ds->data['vmfs']['local'] ?? false) ? ' <span class="badge">'.e(__('local')).'</span>' : '').'</td>',
            '<td class="text-xs">'.e($ds->type).'</td>',
            '<td class="text-xs text-right tabular-nums whitespace-nowrap">'.e(Fmt::bytes($ds->capacity_bytes)).'</td>',
        ],
        'cell' => function ($c, $ds) {
            if (! $c) {
                return ($ds->data['vmfs']['local'] ?? false) ? '' : '<span class="text-warn" title="'.e(__('not mounted')).'">✗</span>';
            }

            return $c['ok'] ? '<span class="text-ok" title="'.e($c['mode']).'">✓</span>' : '<span class="text-crit font-bold" title="'.e(__('not accessible')).'">!</span>';
        },
        'missing' => fn ($ds, $cells, $hosts) => ! ($ds->data['vmfs']['local'] ?? false) && (count($cells) < $hosts->count() || collect($cells)->contains(fn ($c) => ! $c['ok'])),
    ])
@endsection

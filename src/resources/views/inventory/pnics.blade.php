{{--
    Physical adapters: host × vmnic grid. A cell shows the link speed (colour by speed class) or "down";
    hovering it opens a card with the adapter's facts and a copy button for the MAC (data-hovercard, app.js).
    $pnics: InventoryController::pnicGrid() · $vcenters · $multi
--}}
@php
    $speedLabel = fn ($mb) => $mb >= 1000 ? rtrim(rtrim(number_format($mb / 1000, 1, '.', ''), '0'), '.').'G' : $mb.'M';
    // colour by speed class: 1G light green · 10G green · 25/40G light blue · 100G+ blue · below 1G amber
    $speedClass = fn ($mb) => match (true) {
        $mb >= 100000 => 'bg-info text-white',
        $mb >= 25000 => 'bg-info/20 text-info',
        $mb >= 10000 => 'bg-ok text-white',
        $mb >= 1000 => 'bg-ok/20 text-ok',
        default => 'bg-warn/20 text-warn',
    };
    $hostLabel = fn ($h) => ($h->cluster ?? __('Standalone')).($multi ? ' · '.($vcenters[$h->vcenter_id]->name ?? '') : '');
@endphp

<p class="text-xs text-muted mb-2 flex flex-wrap items-center gap-x-3 gap-y-1">
    <span class="pnic bg-ok/20 text-ok">1G</span>
    <span class="pnic bg-ok text-white">10G</span>
    <span class="pnic bg-info/20 text-info">25G</span>
    <span class="pnic bg-info text-white">100G</span>
    <span class="inline-flex items-center gap-1"><span class="pnic pnic-down-crit">down</span> {{ __('uplink without link') }}</span>
    <span class="inline-flex items-center gap-1"><span class="pnic pnic-down">down</span> {{ __('not used, no link') }}</span>
    <span class="inline-flex items-center gap-1"><span class="pnic pnic-unused bg-ok/20 text-ok">1G</span> {{ __('link up, not used by a switch') }}</span>
</p>

@if ($pnics['rows'])
    <div class="card">
        <div class="card-h">
            <span class="text-xs text-muted">{{ __(':n hosts', ['n' => count($pnics['rows'])]) }}</span>
            <div class="flex items-center gap-2">
                <input type="search" data-filter="t-pnics" class="input py-1 w-48" placeholder="{{ __('Filter…') }}" autocomplete="off">
                @include('partials.export-button', ['table' => 't-pnics', 'name' => $exportName.'-physical-adapters'])
            </div>
        </div>
        <div class="table-scroll">
            <table class="table table-matrix table-sticky !w-auto" id="t-pnics" data-crosshair>
                <thead>
                    <tr>
                        <th>{{ __('Cluster') }}</th>
                        <th>{{ __('Host') }}</th>
                        @foreach ($pnics['devices'] as $dev)
                            <th class="!px-1 text-center !normal-case" data-nosort title="{{ $dev }}">{{ preg_replace('/^\D+/', '', $dev) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pnics['rows'] as $row)
                        @php $h = $row['host']; @endphp
                        <tr>
                            <td class="text-xs whitespace-nowrap text-muted">{{ $hostLabel($h) }}</td>
                            <td class="whitespace-nowrap font-medium">
                                <a href="{{ route('hosts.show', $h->ref()) }}" class="inline-flex items-center gap-1.5 hover:text-accent">
                                    @include('partials.dot', ['level' => Fmt::vsLevel($h->overall_status)])
                                    {{ $h->name }}
                                </a>
                            </td>
                            @foreach ($pnics['devices'] as $dev)
                                @php
                                    $p = $row['cells'][$dev] ?? null;
                                    $up = $p && $p['speed_mb'];
                                    $used = $p && $p['roles'];
                                @endphp
                                @if (! $p)
                                    <td class="!px-1 bg-surface-2/60"></td>
                                @else
                                    <td class="!px-1 text-center" data-hovercard
                                        data-export="{{ $up ? $speedLabel($p['speed_mb']) : 'down' }}{{ $used ? '' : ' ('.__('not used').')' }}">
                                        @if ($up)
                                            <span @class(['pnic', $speedClass($p['speed_mb']), 'pnic-unused' => ! $used])>{{ $speedLabel($p['speed_mb']) }}</span>
                                        @else
                                            <span @class(['pnic', 'pnic-down-crit' => $used, 'pnic-down' => ! $used])>down</span>
                                        @endif
                                        <template>
                                            <div class="font-semibold text-sm mb-1.5 flex items-center gap-2">
                                                {{ $p['device'] }}
                                                <span class="text-xs font-normal text-muted">{{ $h->name }}</span>
                                            </div>
                                            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs">
                                                <dt class="text-muted">{{ __('Link') }}</dt>
                                                <dd>@if ($up) <span class="text-ok">{{ $speedLabel($p['speed_mb']) }}</span> · {{ $p['duplex'] ? __('full duplex') : __('half duplex') }} @else <span class="text-crit">{{ __('down') }}</span> @endif</dd>
                                                <dt class="text-muted">MAC</dt>
                                                <dd class="flex items-center gap-1.5">
                                                    <span class="font-mono">{{ $p['mac'] }}</span>
                                                    <button type="button" class="btn btn-sm !px-1.5 !py-0" data-copy="{{ $p['mac'] }}" title="{{ __('Copy') }}">
                                                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                                                        <span data-copy-label>{{ __('Copy') }}</span>
                                                    </button>
                                                </dd>
                                                <dt class="text-muted">{{ __('Driver') }}</dt>
                                                <dd class="font-mono">{{ $p['driver'] ?? '—' }}</dd>
                                                <dt class="text-muted">PCI</dt>
                                                <dd class="font-mono">{{ $p['pci'] ?? '—' }}</dd>
                                                <dt class="text-muted">{{ __('Used by') }}</dt>
                                                <dd>{!! $used ? collect($p['roles'])->map(fn ($r) => e($r))->implode('<br>') : '<span class="text-muted">'.e(__('no switch')).'</span>' !!}</dd>
                                            </dl>
                                        </template>
                                    </td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@else
    <div class="card px-3 py-6 text-center text-sm text-muted">{{ __('No data yet.') }}</div>
@endif

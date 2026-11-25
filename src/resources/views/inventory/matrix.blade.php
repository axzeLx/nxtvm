{{--
    Single object × host matrix. First column = cluster; host columns grouped under cluster headers.
    $matrix: InventoryController::matrix()
    $id:     table id
    $heads:  header labels of the object columns
    $left:   fn($object, $cells) => [html cells]   (object columns)
    $cell:   fn($cell|null, $object, $row) => html (host cell of the row's own cluster)
    $missing: fn($object, $cells, $hosts) => bool  (row has a gap that matters)
    $exportName: optional — shows an Export button (file name)
--}}
@php $multi = $vcenters->count() > 1; $clusterLabel = fn ($vc, $cl) => collect((array) ($cl ?? [null]))->map(fn ($c) => $c ?? __('Standalone'))->implode(', ').($multi ? ' · '.($vcenters[$vc]->name ?? '') : ''); @endphp
@if ($matrix['rows'])
    <div class="card">
        <div class="card-h">
            <span class="text-xs text-muted">{{ __(':n rows', ['n' => count($matrix['rows'])]) }}</span>
            <div class="flex items-center gap-3">
                <label class="text-xs text-muted flex items-center gap-1.5">
                    <input type="checkbox" class="accent-[var(--color-accent)]"
                           onchange="document.querySelectorAll('#{{ $id }} tbody tr').forEach(r => r.classList.toggle('hidden', this.checked && !r.dataset.gap))">
                    {{ __('Only rows with gaps') }}
                </label>
                <input type="search" data-filter="{{ $id }}" class="input py-1 w-48" placeholder="{{ __('Filter…') }}" autocomplete="off">
                @isset($exportName) @include('partials.export-button', ['table' => $id, 'name' => $exportName]) @endisset
            </div>
        </div>
        <div class="table-scroll">
            <table class="table table-matrix table-sticky !w-auto" id="{{ $id }}" data-crosshair>
                <thead>
                    <tr>
                        <th colspan="{{ count($heads) + 1 }}" class="!border-b-0"></th>
                        @foreach ($matrix['groups'] as $g)
                            <th colspan="{{ $g['hosts']->count() }}" data-nosort class="!text-center !px-1 border-l border-line !normal-case !tracking-normal text-fg">{{ $clusterLabel($g['vcenter'], $g['cluster']) }}</th>
                        @endforeach
                    </tr>
                    <tr>
                        <th>{{ __('Cluster') }}</th>
                        @foreach ($heads as $h) <th>{{ $h }}</th> @endforeach
                        @foreach ($matrix['groups'] as $g)
                            @foreach ($g['hosts'] as $i => $host)
                                <th @class(['!px-1 text-center align-bottom !normal-case !tracking-normal', 'border-l border-line' => $i === 0]) data-nosort title="{{ $host->name }}">
                                    <a href="{{ route('hosts.show', $host->ref()) }}" class="hover:text-accent [writing-mode:vertical-rl] rotate-180 inline-block max-h-28 truncate">{{ $host->name }}</a>
                                </th>
                            @endforeach
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($matrix['rows'] as $row)
                        @php $gap = $missing($row['object'], $row['cells'], $row['hosts']); @endphp
                        <tr @if ($gap) data-gap="1" @endif>
                            <td class="text-xs whitespace-nowrap text-muted">{{ $clusterLabel($row['vcenter'], $row['cluster']) }}</td>
                            @foreach ($left($row['object'], $row['cells']) as $html) {!! $html !!} @endforeach
                            @foreach ($matrix['groups'] as $g)
                                @foreach ($g['hosts'] as $i => $host)
                                    @if (isset($row['members'][$host->moid]))
                                        <td @class(['!px-1 text-center text-xs', 'border-l border-line' => $i === 0])>{!! $cell($row['cells'][$host->moid] ?? null, $row['object'], $row) !!}</td>
                                    @else
                                        <td @class(['!px-1 bg-surface-2/60', 'border-l border-line' => $i === 0])></td>
                                    @endif
                                @endforeach
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

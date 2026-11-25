@extends('layouts.app', ['title' => __('Licenses'), 'wide' => true])

@section('content')
    @include('inventory.toolbar', ['title' => __('Licenses'), 'count' => $licenses->count(), 'export' => 't-licenses'])
    @php $multi = $vcenters->count() > 1; $partial = $licenses->contains(fn ($l) => ($l->data['source'] ?? '') === 'assignments'); @endphp
    @if ($partial)
        <div class="mb-3 rounded-md border border-info/30 bg-info/10 px-3 py-2 text-xs text-info">
            {{ __('The vCenter account cannot read the license list (privilege Global → Licenses), so capacity and expiry are not shown. The licenses below are derived from their assignments.') }}
        </div>
    @endif
    <div class="card overflow-x-auto">
        <table class="table" id="t-licenses" data-groups>
            <thead>
                <tr>
                    <th>{{ __('License') }}</th>
                    @if ($multi) <th>vCenter</th> @endif
                    <th>{{ __('Key') }}</th>
                    <th class="text-right">{{ __('Used') }}</th>
                    <th class="text-right">{{ __('Total') }}</th>
                    <th>{{ __('Unit') }}</th>
                    <th>{{ __('Expires') }}</th>
                    <th>{{ __('Assigned to') }}</th>
                </tr>
            </thead>
            @foreach ($licenses as $l)
                @php
                    $days = $l->expires_at ? (int) now()->diffInDays($l->expires_at, false) : null;
                    $over = $l->total !== null && $l->total > 0 && $l->used > $l->total;
                    $assigned = collect($l->data['assigned'] ?? [])->sortBy('name', SORT_NATURAL);
                @endphp
                <tbody>
                    <tr class="align-top">
                        <td class="font-medium whitespace-nowrap">{{ $l->name }}<div class="text-[11px] text-muted font-normal">{{ $l->edition }}</div></td>
                        @if ($multi) @include('partials.vcname', ['row' => $l]) @endif
                        <td class="font-mono text-xs whitespace-nowrap">{{ $l->license_key }}</td>
                        <td @class(['text-xs text-right tabular-nums', 'text-crit font-semibold' => $over])>{{ $l->used ?? '—' }}</td>
                        <td class="text-xs text-right tabular-nums">{{ $l->total === 0 ? __('unlimited') : ($l->total ?? '—') }}</td>
                        <td class="text-xs text-muted">{{ $l->unit }}</td>
                        <td class="text-xs whitespace-nowrap" data-sort="{{ $l->expires_at?->timestamp }}">
                            @if ($l->expires_at)
                                <span class="text-{{ \App\Services\Collector\Certificates::level($days) }}">{{ Fmt::date($l->expires_at) }}</span>
                                <span class="text-muted">({{ $days }} d)</span>
                            @elseif (($l->data['source'] ?? '') === 'license_manager') <span class="text-muted">{{ __('never') }}</span>
                            @else — @endif
                        </td>
                        <td class="text-xs">
                            <span class="badge">{{ $assigned->count() }}</span>
                            {{ $assigned->pluck('name')->implode(', ') }}
                        </td>
                    </tr>
                </tbody>
            @endforeach
        </table>
        @if ($licenses->isEmpty()) <p class="px-3 py-6 text-center text-sm text-muted">{{ __('No data yet.') }}</p> @endif
    </div>
@endsection

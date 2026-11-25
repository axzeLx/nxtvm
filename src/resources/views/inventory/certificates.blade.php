@extends('layouts.app', ['title' => __('Certificates'), 'wide' => true])

@section('content')
    @include('inventory.toolbar', ['title' => __('Certificates'), 'count' => $certificates->count(), 'table' => 't-certs'])
    @php
        $multi = $vcenters->count() > 1;
        $warn = config('nxtvm.cert_warn_days'); $crit = config('nxtvm.cert_crit_days');
    @endphp
    <p class="text-xs text-muted -mt-2 mb-3">
        {{ __('Sorted by expiry. Warning below :w days, critical below :c days.', ['w' => $warn, 'c' => $crit]) }}
        {{ __('Trusted root and STS certificates need appliance privileges and are shown only when readable.') }}
    </p>
    <div class="card overflow-x-auto">
        <table class="table" id="t-certs">
            <thead>
                <tr>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Scope') }}</th>
                    <th>{{ __('Owner') }}</th>
                    @if ($multi) <th>vCenter</th> @endif
                    <th>{{ __('Subject') }}</th>
                    <th>{{ __('Issuer') }}</th>
                    <th>{{ __('Valid from') }}</th>
                    <th>{{ __('Expires') }}</th>
                    <th class="text-right">{{ __('Days left') }}</th>
                    <th>SAN</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($certificates as $c)
                    @php
                        $days = $c->valid_to ? (int) now()->diffInDays($c->valid_to, false) : null;
                        $lvl = \App\Services\Collector\Certificates::level($days);
                        $selfIssued = ($c->data['issuer'] ?? '') === ($c->data['subject'] ?? null);
                    @endphp
                    <tr>
                        <td data-sort="{{ $days }}">
                            <span class="badge badge-{{ $lvl }}">{{ $days < 0 ? __('expired') : ($lvl === 'ok' ? __('valid') : __('expiring')) }}</span>
                        </td>
                        <td class="text-xs whitespace-nowrap">{{ ['vcenter' => 'vCenter', 'host' => 'ESXi', 'root' => __('Trusted root')][$c->scope] ?? $c->scope }}
                            @if ($c->data['role'] ?? null) <span class="text-muted">· {{ $c->data['role'] }}</span> @endif</td>
                        <td class="text-xs whitespace-nowrap font-medium">
                            @if ($c->scope === 'host' && $c->owner_moid)
                                <a href="{{ route('hosts.show', ['vc' => $c->vcenter_id, 'moid' => $c->owner_moid]) }}" class="hover:text-accent">{{ $c->owner }}</a>
                            @else {{ $c->owner }} @endif
                        </td>
                        @if ($multi) @include('partials.vcname', ['row' => $c]) @endif
                        <td class="text-xs">{{ $c->subject }}</td>
                        <td class="text-xs">{{ $c->issuer }}@if ($c->data['issuer_o'] ?? null) <span class="text-muted">· {{ $c->data['issuer_o'] }}</span>@endif
                            @if ($selfIssued) <span class="badge">{{ __('self-signed') }}</span> @endif</td>
                        <td class="text-xs tabular-nums whitespace-nowrap">{{ Fmt::date($c->valid_from) }}</td>
                        <td class="text-xs tabular-nums whitespace-nowrap text-{{ $lvl }}">{{ Fmt::date($c->valid_to) }}</td>
                        <td class="text-xs text-right tabular-nums text-{{ $lvl }}">{{ $days }}</td>
                        <td class="text-[11px] text-muted max-w-72 truncate" title="{{ $c->data['san'] ?? '' }}">{{ $c->data['san'] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($certificates->isEmpty()) <p class="px-3 py-6 text-center text-sm text-muted">{{ __('No data yet.') }}</p> @endif
    </div>
@endsection

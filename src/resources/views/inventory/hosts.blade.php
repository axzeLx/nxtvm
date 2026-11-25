@extends('layouts.app', ['title' => __('Hosts'), 'wide' => true])

@section('content')
    @include('inventory.toolbar', ['title' => __('Hosts'), 'count' => $hosts->count(), 'table' => 't-hosts'])
    @php $multi = $vcenters->count() > 1; $builds = $hosts->groupBy('cluster')->map(fn ($g) => $g->pluck('build')->unique()->count()); @endphp
    <div class="card overflow-x-auto">
        <table class="table" id="t-hosts">
            <thead>
                <tr>
                    <th>{{ __('Host') }}</th>
                    @if ($multi) <th>vCenter</th> @endif
                    <th>{{ __('Cluster') }}</th>
                    <th>{{ __('State') }}</th>
                    <th>{{ __('Model') }}</th>
                    <th>{{ __('Serial') }}</th>
                    <th>CPU</th>
                    <th class="text-right" title="{{ __('Physical CPU packages (sockets)') }}">{{ __('CPUs') }}</th>
                    <th class="text-right" title="{{ __('Physical cores') }}">{{ __('Cores') }}</th>
                    <th class="text-right" title="{{ __('Cores counted for per-core licensing: at least :n per CPU', ['n' => config('nxtvm.license_min_cores')]) }}">{{ __('Lic. cores') }}</th>
                    <th class="text-right">RAM</th>
                    <th>{{ __('CPU %') }}</th>
                    <th>{{ __('Mem %') }}</th>
                    <th>ESXi</th>
                    <th class="text-right">{{ __('Uptime') }}</th>
                    <th class="text-right">VMs</th>
                    <th>NTP</th>
                    <th>SSH</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($hosts as $h)
                    @php
                        $d = $h->data;
                        $svc = collect($d['services'] ?? [])->keyBy('key');
                        $ntpd = $svc['ntpd'] ?? null; $ssh = $svc['TSM-SSH'] ?? null;
                        $cpuPct = Fmt::pct($h->cpu_usage_mhz, $h->cpu_mhz * $h->cpu_cores);
                        $memPct = Fmt::pct($h->mem_usage_mb * 1048576, $h->mem_bytes);
                        $state = $h->connection_state !== 'connected' ? ['crit', $h->connection_state] : ($h->maintenance ? ['warn', __('maintenance')] : ['ok', __('connected')]);
                    @endphp
                    <tr>
                        <td class="whitespace-nowrap" data-sort="{{ $h->name }}">
                            <a href="{{ route('hosts.show', $h->ref()) }}" class="inline-flex items-center gap-1.5 font-medium hover:text-accent">
                                @include('partials.dot', ['level' => Fmt::vsLevel($h->overall_status), 'title' => $h->overall_status])
                                {{ $h->name }}
                            </a>
                        </td>
                        @if ($multi) @include('partials.vcname', ['row' => $h]) @endif
                        <td class="text-xs whitespace-nowrap">{{ $h->cluster ?? '—' }}</td>
                        <td class="whitespace-nowrap"><span class="badge badge-{{ $state[0] }}">{{ $state[1] }}</span></td>
                        <td class="text-xs whitespace-nowrap" title="{{ $h->vendor }} {{ $h->model }}">{{ Str::before($h->vendor ?? '', ' ') }} {{ $h->model }}</td>
                        <td class="text-xs font-mono">{{ $h->serial ?? '—' }}</td>
                        <td class="text-xs whitespace-nowrap" title="{{ $h->cpu_model }}">
                            {{ Fmt::cpuShort($h->cpu_model) }}
                            <span class="text-muted">· {{ $h->coresPerCpu() ?? '?' }}c{{ ($d['hyperthreading'] ?? false) ? ' HT' : '' }}</span>
                        </td>
                        <td class="text-xs text-right tabular-nums">{{ $h->cpu_sockets }}</td>
                        <td class="text-xs text-right tabular-nums">{{ $h->cpu_cores }}</td>
                        <td @class(['text-xs text-right tabular-nums', 'text-muted' => $h->licensedCores() === (int) $h->cpu_cores, 'text-warn' => $h->licensedCores() > (int) $h->cpu_cores])
                            @if ($h->licensedCores() > (int) $h->cpu_cores) title="{{ __(':c cores per CPU, licensed as :n', ['c' => $h->coresPerCpu(), 'n' => config('nxtvm.license_min_cores')]) }}" @endif>{{ $h->licensedCores() }}</td>
                        <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $h->mem_bytes }}" data-f="{{ $h->mem_bytes }}">{{ Fmt::bytes($h->mem_bytes) }}</td>
                        <td data-sort="{{ $cpuPct }}">@include('partials.bar', ['pct' => $cpuPct])</td>
                        <td data-sort="{{ $memPct }}">@include('partials.bar', ['pct' => $memPct])</td>
                        <td class="text-xs whitespace-nowrap" data-sort="{{ $h->version }}.{{ $h->build }}">
                            {{ $h->version }} <span @class(['text-muted' => ($builds[$h->cluster] ?? 1) < 2, 'text-warn' => ($builds[$h->cluster] ?? 1) > 1])
                                  title="{{ ($builds[$h->cluster] ?? 1) > 1 ? __('Different builds in this cluster') : '' }}">· {{ $h->build }}</span>
                        </td>
                        <td class="text-xs text-right tabular-nums whitespace-nowrap" data-sort="{{ $d['uptime_s'] ?? '' }}">{{ Fmt::duration($d['uptime_s'] ?? null) }}</td>
                        <td class="text-xs text-right tabular-nums">{{ $h->vm_count }}</td>
                        <td class="text-xs whitespace-nowrap" title="{{ implode(', ', $d['ntp']['servers'] ?? []) }}">
                            @if (empty($d['ntp']['servers'])) <span class="text-crit">{{ __('none') }}</span>
                            @elseif ($ntpd && $ntpd['running']) <span class="text-ok">✓</span> <span class="text-muted">{{ count($d['ntp']['servers']) }}</span>
                            @else <span class="text-warn">{{ __('stopped') }}</span> @endif
                        </td>
                        <td class="text-xs">
                            @if ($ssh && $ssh['running']) <span class="text-warn">{{ __('on') }}</span> @else <span class="text-muted">{{ __('off') }}</span> @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            @if ($hosts->isNotEmpty())
                @php $before = 6 + ($multi ? 1 : 0); @endphp
                {{-- totals of the visible rows (recomputed when filtering) --}}
                <tfoot class="font-semibold">
                    <tr class="border-t-2 border-line bg-surface-2/60">
                        <td class="text-xs text-muted" colspan="{{ $before }}">{{ __('Total') }} · <span data-count-for="t-hosts">{{ $hosts->count() }}</span> {{ __('hosts') }}</td>
                        <td class="text-xs text-right tabular-nums" data-sum>{{ $hosts->sum('cpu_sockets') }}</td>
                        <td class="text-xs text-right tabular-nums" data-sum>{{ $hosts->sum('cpu_cores') }}</td>
                        <td class="text-xs text-right tabular-nums" data-sum>{{ $hosts->sum(fn ($h) => $h->licensedCores()) }}</td>
                        <td class="text-xs text-right tabular-nums" data-sum="bytes">{{ Fmt::bytes($hosts->sum('mem_bytes')) }}</td>
                        <td colspan="7"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
        @if ($hosts->isEmpty()) <p class="px-3 py-6 text-center text-sm text-muted">{{ __('No data yet.') }}</p> @endif
    </div>
@endsection

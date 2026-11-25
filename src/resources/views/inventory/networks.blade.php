@extends('layouts.app', ['title' => __('Networks'), 'wide' => true])

{{-- Networks: sidebar tabs Port groups · Distributed switches (when there are any) · Physical adapters; open tab in the URL hash --}}
@section('content')
    @include('inventory.toolbar', ['title' => __('Networks'), 'count' => $networks->count()])
    @php
        $multi = $vcenters->count() > 1;
        $cur = app(\App\Support\VcScope::class)->current();
        $exportName = $cur ? Str::slug($cur->name) : 'all-vcenters';
        // an uplink (used by a switch) without link is a problem; an unused port without link is not
        $uplinkDown = collect($pnics['rows'])->contains(fn ($r) => collect($r['cells'])->contains(fn ($p) => ! $p['speed_mb'] && $p['roles']));
        $pgGap = collect($matrix['rows'])->contains(fn ($r) => count($r['cells']) < count($r['members']));
        $tabs = array_filter([
            'portgroups' => [__('Port groups'), 'network', count($matrix['rows']), $pgGap ? 'warn' : null],
            'dvswitches' => $dvswitches->isNotEmpty() ? [__('Distributed switches'), 'cluster', $dvswitches->count(), null] : null,
            'pnics' => [__('Physical adapters'), 'server', count($pnics['rows']), $uplinkDown ? 'crit' : null],
        ]);
    @endphp

    <div x-data="{
            tab: 'portgroups',
            tabs: @js(array_keys($tabs)),
            init() {
                const h = location.hash.slice(1);
                if (this.tabs.includes(h)) this.tab = h;
                window.addEventListener('hashchange', () => { const t = location.hash.slice(1); if (this.tabs.includes(t)) this.tab = t; });
            },
            open(t) { this.tab = t; history.replaceState(null, '', '#' + t); },
         }" class="flex flex-col md:flex-row gap-3 items-start">

        {{-- Sidebar --}}
        <nav class="card p-1 w-full md:w-52 shrink-0 md:sticky md:top-[6.5rem] flex md:flex-col gap-0.5 overflow-x-auto">
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

            {{-- Port groups × hosts --}}
            <section x-show="tab === 'portgroups'">
    <p class="text-xs text-muted mb-2">
        {{ __('Port groups per host.') }}
        <span class="text-ok">✓</span> {{ __('present') }} ·
        <span class="text-warn font-semibold">99</span> {{ __('present with a different VLAN than on the other hosts') }} ·
        <span class="text-crit">✗</span> {{ __('missing on this host') }}
    </p>
    @include('inventory.matrix', [
        'id' => 'mx-net',
        'heads' => [__('Port group'), 'VLAN', __('Switch'), 'VMs'],
        'left' => function ($n, $cells) {
            return [
                '<td class="whitespace-nowrap font-medium"><a class="hover:text-accent" href="'.e(route('networks.show', ['vc' => $n->vcenter_id, 'moid' => $n->moid])).'">'.e($n->name).'</a>'.($n->kind === 'distributed' ? ' <span class="badge">dvpg</span>' : '').'</td>',
                '<td class="text-xs tabular-nums whitespace-nowrap'.($n->vlan === 'mixed' ? ' text-warn' : '').'">'.e($n->vlan ?? '—').'</td>',
                '<td class="text-xs whitespace-nowrap text-muted">'.e(collect($cells)->pluck('switch')->unique()->implode(', ')).'</td>',
                '<td class="text-xs text-right tabular-nums">'.e($n->vm_count).'</td>',
            ];
        },
        'cell' => function ($c, $n, $row) {
            if (! $c) {
                return '<span class="text-crit" title="'.e(__('missing')).'">✗</span>';
            }
            $vlans = collect($row['cells'])->pluck('vlan')->countBy();
            $major = $vlans->sortDesc()->keys()->first();
            if ($vlans->count() > 1 && (string) $c['vlan'] !== (string) $major) {
                return '<span class="text-warn font-semibold" title="VLAN '.e($c['vlan']).' · '.e($c['switch']).'">'.e($c['vlan']).'</span>';
            }

            return '<span class="text-ok" title="VLAN '.e($c['vlan']).' · '.e($c['switch']).'">✓</span>';
        },
        'missing' => fn ($n, $cells, $hosts) => count($cells) < $hosts->count() || collect($cells)->pluck('vlan')->unique()->count() > 1,
        'exportName' => $exportName.'-port-groups',
    ])
            </section>

            @if ($dvswitches->isNotEmpty())
                {{-- Distributed switches --}}
                <section x-show="tab === 'dvswitches'" x-cloak>
                    <div class="card overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Version') }}</th><th class="text-right">MTU</th><th>{{ __('Uplinks') }}</th><th class="text-right">{{ __('Hosts') }}</th><th>LACP</th><th>NIOC</th></tr></thead>
                            <tbody>
                                @foreach ($dvswitches as $s)
                                    <tr>
                                        <td class="font-medium">{{ $s->name }}</td>
                                        <td class="text-xs">{{ $s->version }}</td>
                                        <td class="text-xs text-right tabular-nums">{{ $s->mtu }}</td>
                                        <td class="text-xs">{{ implode(', ', $s->data['uplinks'] ?? []) }}</td>
                                        <td class="text-xs text-right tabular-nums">{{ $s->host_count }}</td>
                                        <td class="text-xs">{{ collect($s->data['lacp'] ?? [])->pluck('name')->implode(', ') ?: '—' }}</td>
                                        <td class="text-xs">{{ ($s->data['nioc'] ?? false) ? __('on') : __('off') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            {{-- Physical adapters × hosts --}}
            <section x-show="tab === 'pnics'" x-cloak>
                @include('inventory.pnics')
            </section>
        </div>
    </div>
@endsection

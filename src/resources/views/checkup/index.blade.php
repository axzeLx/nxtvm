@extends('layouts.app', ['title' => __('Checkup'), 'wide' => true])

{{--
    Checkup: only problems, best-practice deviations and recommendations (not inventory).
    Sidebar tabs: Overview + one per category; the open tab is kept in the URL hash.
--}}
@section('content')
    @if ($prompts)
        <script>window.nxtvmAi = @js($ai->clientConfig() + ['anonymize' => $ai->anonymize()]);</script>
    @endif
    @php
        $cats = \App\Checkup\Category::all();
        $reg = $report->registry;
        $multi = $vcenters->count() > 1;
        $score = $report->score();
        $level = \App\Checkup\Score::level(...);
        $grade = \App\Checkup\Score::grade(...);
        $sevLabel = ['crit' => __('Critical'), 'warn' => __('Warning'), 'info' => __('Advice')];
        $lastRun = $report->runs->min('ran_at');
        $nextRun = $config->scheduled() && $lastRun ? $report->runs->min('ran_at')->addHours($config->hours()) : null;
        $link = fn ($f) => match ($f->object_type) {
            'host' => $f->object_moid ? route('hosts.show', ['vc' => $f->vcenter_id, 'moid' => $f->object_moid]) : null,
            'vm' => $f->object_moid ? route('vms.show', ['vc' => $f->vcenter_id, 'moid' => $f->object_moid]) : null,
            'datastore' => $f->object_moid ? route('datastores.show', ['vc' => $f->vcenter_id, 'moid' => $f->object_moid]) : null,
            default => null,
        };
        $typeLabel = ['host' => __('host'), 'vm' => __('VM'), 'cluster' => __('cluster'), 'datastore' => __('datastore'), 'network' => __('network'),
            'dvswitch' => __('dvSwitch'), 'certificate' => __('certificate'), 'license' => __('license'), 'vcenter' => 'vCenter', 'datacenter' => __('datacenter')];
        // categories that have at least one enabled check in the results
        $activeCats = collect($cats)->filter(fn ($c, $k) => collect($report->checks)->contains('category', $k));
        $tabs = ['overview' => [__('Overview'), 'M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z', null, null]];
        foreach ($activeCats as $k => [$label, $icon]) {
            $cnt = $report->counts($k);
            $worst = $cnt['crit'] ? 'crit' : ($cnt['warn'] ? 'warn' : null);
            $tabs[$k] = [$label, $icon, $report->score($k), $worst];
        }
    @endphp

    <div class="sticky top-[86px] z-30 -mx-4 -mt-4 mb-3 px-4 py-2.5 bg-bg border-b border-line flex flex-wrap items-center gap-x-3 gap-y-1">
        <h1 class="text-base font-bold tracking-wide inline-flex items-center gap-2">
            @include('partials.checkup-icon', ['class' => 'size-5'])
            {{ Str::upper(__('Checkup')) }}:
            {{-- what is checked: the selected vCenter, or all of them --}}
            <span class="tracking-normal">{{ app(\App\Support\VcScope::class)->current()?->name ?? __('All vCenters') }}</span>
        </h1>
        @php $mods = app(\App\Support\Modules::class); @endphp
        @if ($mods->edition() === 'trial')
            <a href="{{ auth()->user()->isAdmin() ? route('settings.license') : '#' }}" class="badge badge-accent" title="{{ __('all modules are available until :date', ['date' => $mods->trialEnds()->toDateString()]) }}">
                {{ __('Trial') }} · {{ trans_choice(':n day left|:n days left', $mods->trialDaysLeft(), ['n' => $mods->trialDaysLeft()]) }}
            </a>
        @endif
        @unless ($report->empty())
            <span @class(['badge text-xs font-semibold', 'badge-ok' => $level($score) === 'ok', 'badge-warn' => $level($score) === 'warn', 'badge-crit' => $level($score) === 'crit'])>
                {{ $score ?? '–' }} · {{ $grade($score) }}
            </span>
        @endunless
        <div class="ml-auto flex items-center gap-3 text-xs text-muted">
            @if ($lastRun)
                <span class="italic" title="{{ Fmt::time($lastRun) }}">{{ __('Last checkup') }} {{ Fmt::ago($lastRun) }}</span>
            @endif
            @unless ($locked)
            <span class="italic">
                @if ($config->scheduled())
                    {{ __('Every :n h', ['n' => $config->hours()]) }}@if ($nextRun) · {{ __('next') }} {{ $nextRun->isPast() ? __('due') : Fmt::ago($nextRun) }}@endif
                @else
                    {{ __('Manual only') }}
                @endif
            </span>
            @endunless
            {{-- without a license the button stays; it leads to the "license needed" page --}}
            @if (auth()->user()->isAdmin())
                <form method="POST" action="{{ route('checkup.run', ['vc' => app(\App\Support\VcScope::class)->key()]) }}" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <button class="btn btn-sm" :disabled="busy">
                        <svg class="size-3.5" :class="busy && 'animate-spin'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>
                        <span x-text="busy ? @js(__('Running…')) : @js(__('Run checkup now'))">{{ __('Run checkup now') }}</span>
                    </button>
                </form>
                <a href="{{ route('settings.checkup') }}" class="hover:text-fg" title="{{ __('Checkup settings') }}">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                </a>
            @endif
        </div>
    </div>

    @if ($locked)
        {{-- trial over / not licensed: the last results stay, new checkups need a license --}}
        <div class="mb-3 rounded-md border border-warn/30 bg-warn/10 px-3 py-2 text-sm flex flex-wrap items-center gap-x-3 gap-y-1">
            @include('partials.icon', ['name' => 'lock', 'class' => 'size-4 text-warn shrink-0'])
            <span>{{ __('Read-only: these are the results of the last checkup. New checkups need a license key.') }}</span>
            @if (auth()->user()->isAdmin())
                <a href="{{ route('settings.license') }}" class="ml-auto btn btn-sm">{{ __('Enter license key') }}</a>
            @endif
        </div>
    @endif
    @include('partials.flash')

    @if ($report->empty())
        <div class="card px-4 py-12 text-center">
            <p class="text-sm">{{ __('No checkup has run yet for this selection.') }}</p>
            <p class="mt-1 text-xs text-muted">
                @if ($config->scheduled())
                    {{ __('The scheduled checkup runs within a minute after inventory has been collected.') }}
                @else
                    {{ __('Checkups run manually (Settings → Checkup).') }}
                @endif
            </p>
        </div>
    @else
    <div x-data="{
            tab: 'overview',
            tabs: @js(array_keys($tabs)),
            init() {
                const h = location.hash.slice(1);
                if (this.tabs.includes(h)) this.tab = h;
                window.addEventListener('hashchange', () => { const t = location.hash.slice(1); if (this.tabs.includes(t)) this.tab = t; });
            },
            open(t, anchor = null) {
                this.tab = t; history.replaceState(null, '', '#' + t);
                this.$nextTick(() => anchor ? document.getElementById(anchor)?.scrollIntoView({ behavior: 'smooth', block: 'start' }) : window.scrollTo({ top: 0 }));
            },
         }" class="flex flex-col md:flex-row gap-3 items-start">

        {{-- Sidebar --}}
        <nav class="card p-1 w-full md:w-56 shrink-0 md:sticky md:top-[9rem] flex md:flex-col gap-0.5 overflow-x-auto">
            @foreach ($tabs as $key => [$label, $icon, $catScore, $flag])
                <button type="button" @click="open(@js($key))"
                        :class="tab === @js($key) ? 'bg-surface-2 text-fg font-medium' : 'text-muted hover:text-fg hover:bg-surface-2/60'"
                        class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm text-left whitespace-nowrap cursor-pointer">
                    <svg class="size-4 shrink-0" :class="tab === @js($key) && 'text-accent'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                    <span>{{ $label }}</span>
                    @if ($flag) <span class="dot bg-{{ $flag }}"></span> @endif
                    @if ($key !== 'overview')
                        <span class="ml-auto text-[11px] tabular-nums text-{{ $level($catScore) === 'unknown' ? 'muted' : $level($catScore) }}">{{ $catScore ?? '–' }}</span>
                    @endif
                </button>
            @endforeach
        </nav>

        <div class="flex-1 min-w-0 w-full">

            {{-- Overview --}}
            <section x-show="tab === 'overview'" class="space-y-3">
                <div class="grid gap-3 lg:grid-cols-[17rem_1fr] items-start">
                    {{-- score --}}
                    <div class="card p-4 flex flex-col items-center">
                        @php $lv = $level($score); $dash = 2 * M_PI * 52; @endphp
                        <svg viewBox="0 0 120 120" class="size-40 -rotate-90" aria-hidden="true">
                            <circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="10" class="text-surface-2"/>
                            <circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round"
                                    class="text-{{ $lv === 'unknown' ? 'muted' : $lv }}" stroke-dasharray="{{ $dash }}" stroke-dashoffset="{{ $dash * (1 - ($score ?? 0) / 100) }}"/>
                        </svg>
                        <div class="-mt-[6.6rem] mb-12 text-center">
                            <div class="text-4xl font-bold tabular-nums">{{ $score ?? '–' }}</div>
                            <div class="text-sm font-semibold text-{{ $lv === 'unknown' ? 'muted' : $lv }}">{{ __('Grade') }} {{ $grade($score) }}</div>
                        </div>
                        @php $all = $report->counts(); @endphp
                        <div class="mt-1 flex gap-2 text-xs">
                            <span class="badge badge-crit">{{ $all['crit'] }} {{ __('critical') }}</span>
                            <span class="badge badge-warn">{{ $all['warn'] }} {{ __('warnings') }}</span>
                            <span class="badge badge-info">{{ $all['info'] }} {{ __('advice') }}</span>
                        </div>
                        @if ($multi)
                            <div class="mt-4 w-full border-t border-line pt-3 space-y-1.5">
                                @foreach ($vcenters as $vc)
                                    @php $run = $report->runs->get($vc->id); @endphp
                                    <a href="{{ route('checkup', ['vc' => $vc->id]) }}" class="flex items-center gap-2 text-sm hover:text-accent">
                                        <span class="truncate">{{ $vc->name }}</span>
                                        <span class="ml-auto tabular-nums font-semibold text-{{ $level($run?->score) === 'unknown' ? 'muted' : $level($run?->score) }}">{{ $run?->score ?? '–' }}</span>
                                        <span class="w-4 text-xs text-muted">{{ $grade($run?->score) }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- category scores --}}
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5">
                        @foreach ($activeCats as $k => [$label, $icon])
                            @php $cs = $report->score($k); $cn = $report->counts($k); $cl = $level($cs); @endphp
                            <button type="button" @click="open(@js($k))" class="card p-3 text-left hover:border-accent/50 cursor-pointer">
                                <div class="flex items-center gap-2 text-sm text-muted">
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                                    {{ $label }}
                                </div>
                                <div class="mt-1 flex items-baseline gap-1.5">
                                    <span class="text-2xl font-semibold tabular-nums text-{{ $cl === 'unknown' ? 'muted' : $cl }}">{{ $cs ?? '–' }}</span>
                                    <span class="text-xs text-muted">{{ $grade($cs) }}</span>
                                </div>
                                <div class="mt-2 h-1.5 rounded-full bg-surface-2 overflow-hidden">
                                    <div class="h-full rounded-full bg-{{ $cl === 'unknown' ? 'unknown' : $cl }}" style="width: {{ $cs ?? 0 }}%"></div>
                                </div>
                                <div class="mt-2 flex gap-2 text-[11px] text-muted tabular-nums">
                                    @if ($cn['crit']) <span class="text-crit">{{ $cn['crit'] }} {{ __('crit') }}</span> @endif
                                    @if ($cn['warn']) <span class="text-warn">{{ $cn['warn'] }} {{ __('warn') }}</span> @endif
                                    @if ($cn['info']) <span>{{ $cn['info'] }} {{ __('advice') }}</span> @endif
                                    @if (! array_sum($cn)) <span class="text-ok">{{ __('No problems') }}</span> @endif
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- top problems --}}
                @php $top = $report->problems()->reject(fn ($c) => $c['status'] === 'info')->take(12); @endphp
                <div class="card">
                    <div class="card-h"><h2 class="card-t">{{ __('Top problems') }}</h2><span class="text-xs text-muted">{{ __('Ordered by impact on the score') }}</span></div>
                    @if ($top->isEmpty())
                        <p class="px-4 py-8 text-center text-sm text-ok">{{ __('No problems found.') }}</p>
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($top as $key => $c)
                                @php $chk = $reg->get($key); @endphp
                                <li>
                                    <button type="button" @click="open(@js($c['category']), @js('chk-'.$key))" class="w-full flex items-center gap-3 px-3 py-2 text-left hover:bg-surface-2/60 cursor-pointer">
                                        @include('partials.dot', ['level' => $c['status']])
                                        <span class="text-sm font-medium">{{ $chk->title }}</span>
                                        <span class="badge">{{ $cats[$c['category']][0] }}</span>
                                        <span class="ml-auto text-xs text-muted tabular-nums whitespace-nowrap">
                                            {{ trans_choice(':n finding|:n findings', $c['crit'] + $c['warn'], ['n' => $c['crit'] + $c['warn']]) }}@if ($c['total']) · {{ __('of :n checked', ['n' => $c['total']]) }}@endif
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            {{-- Category tabs --}}
            @foreach ($activeCats as $cat => [$catLabel])
                <section x-show="tab === @js($cat)" x-cloak class="space-y-3">
                    @php $problems = $report->problems($cat); $passed = $report->passed($cat); $na = $report->notApplicable($cat); @endphp

                    @forelse ($problems as $key => $c)
                        @php $chk = $reg->get($key); $rows = $report->findings->get($key, collect()); @endphp
                        <div id="chk-{{ $key }}" class="card scroll-mt-36" x-data="{ all: false }">
                            <div class="card-h">
                                <div class="flex items-center gap-2 min-w-0">
                                    @include('partials.dot', ['level' => $c['status']])
                                    <h2 class="card-t">{{ $chk->title }}</h2>
                                    @foreach (['crit', 'warn', 'info'] as $s)
                                        @if ($c[$s]) <span class="badge badge-{{ $s }}">{{ $c[$s] }} {{ Str::lower($sevLabel[$s]) }}</span> @endif
                                    @endforeach
                                </div>
                                <div class="flex items-center gap-3 shrink-0">
                                    @if ($c['total'])
                                        <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ __(':n of :t checked', ['n' => $rows->unique(fn ($f) => $f->vcenter_id.$f->object_type.$f->object_name)->count(), 't' => $c['total']]) }}</span>
                                    @endif
                                    @if (is_string($prompts[$key] ?? null))
                                        @include('checkup.ask-ai', ['prompt' => $prompts[$key], 'title' => $chk->title])
                                    @endif
                                </div>
                            </div>
                            <p class="px-3 py-2 text-xs text-muted flex gap-2 border-b border-line bg-surface-2/40">
                                <svg class="size-4 shrink-0 text-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.3h6c0-1 .4-1.8 1-2.3A7 7 0 0 0 12 2z"/></svg>
                                <span>{{ $chk->advice }}</span>
                            </p>
                            <div class="overflow-x-auto">
                                <table class="table">
                                    <thead><tr>
                                        <th class="w-6" data-nosort></th>
                                        @if ($multi) <th>vCenter</th> @endif
                                        <th>{{ __('Object') }}</th>
                                        <th>{{ __('Finding') }}</th>
                                        @if (is_array($prompts[$key] ?? null)) <th class="w-20" data-nosort></th> @endif
                                    </tr></thead>
                                    <tbody>
                                        @foreach ($rows as $i => $f)
                                            <tr @if ($loop->index >= 10) x-show="all" x-cloak @endif>
                                                <td title="{{ $sevLabel[$f->severity] ?? '' }}">@include('partials.dot', ['level' => $f->severity])</td>
                                                @if ($multi) <td class="text-xs text-muted whitespace-nowrap">{{ $vcenters->get($f->vcenter_id)?->name }}</td> @endif
                                                <td class="whitespace-nowrap">
                                                    @if ($url = $link($f))
                                                        <a href="{{ $url }}" class="font-medium hover:text-accent">{{ $f->object_name }}</a>
                                                    @else
                                                        <span class="font-medium">{{ $f->object_name ?? '—' }}</span>
                                                    @endif
                                                    <span class="ml-1 text-[11px] text-muted">{{ $typeLabel[$f->object_type] ?? $f->object_type }}</span>
                                                </td>
                                                <td class="text-sm break-words">{{ $f->message }}</td>
                                                @if (is_array($prompts[$key] ?? null))
                                                    <td class="text-right">
                                                        @isset ($prompts[$key][$f->id])
                                                            @include('checkup.ask-ai', ['prompt' => $prompts[$key][$f->id], 'title' => Str::beforeLast($f->message, ' — '), 'row' => true])
                                                        @endisset
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($rows->count() > 10)
                                <button type="button" @click="all = !all" class="w-full border-t border-line px-3 py-1.5 text-xs text-accent hover:bg-surface-2/60 cursor-pointer"
                                        x-text="all ? @js(__('Show less')) : @js(__('Show all :n', ['n' => $rows->count()]))">{{ __('Show all :n', ['n' => $rows->count()]) }}</button>
                            @endif
                        </div>
                    @empty
                        <div class="card px-4 py-8 text-center text-sm text-ok">{{ __('No problems in this category.') }}</div>
                    @endforelse

                    @if ($passed->isNotEmpty())
                        <details class="card group">
                            <summary class="card-h cursor-pointer list-none">
                                <span class="card-t inline-flex items-center gap-2"><span class="dot bg-ok"></span>{{ __('Passed') }} ({{ $passed->count() }})</span>
                                <span class="text-xs text-muted group-open:hidden">{{ __('show') }}</span>
                            </summary>
                            <ul class="divide-y divide-line">
                                @foreach ($passed as $key => $c)
                                    <li class="px-3 py-1.5 flex items-center gap-2 text-sm">
                                        <svg class="size-4 text-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>
                                        {{ $reg->get($key)->title }}
                                        <span class="ml-auto text-xs text-muted tabular-nums">{{ __(':n checked', ['n' => $c['total']]) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif

                    @if ($na->isNotEmpty())
                        <div class="card">
                            <div class="card-h"><span class="card-t text-muted">{{ __('Not applicable') }}</span></div>
                            <ul class="divide-y divide-line">
                                @foreach ($na as $key => $c)
                                    <li class="px-3 py-1.5 flex items-center gap-2 text-sm text-muted">
                                        <span class="dot bg-unknown"></span>
                                        {{ $reg->get($key)->title }}
                                        <span class="ml-auto text-xs @if ($c['status'] === 'error') text-crit @endif">{{ $c['error'] ?? $c['na'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
    @endif
@endsection

{{--
    Selected vCenter in the top breadcrumb, with a switcher. Switching keeps the current section;
    on a detail page it goes to that section's list.
--}}
@php
    $scope = app(\App\Support\VcScope::class);
    $all = $scope->vcenters();
    $cur = $scope->current();
    $list = Str::before(Route::currentRouteName(), '.show');
    $counts = fn ($vc) => $vc->inventory['counts'] ?? [];
    $sum = fn ($key) => $all->sum(fn ($vc) => $counts($vc)[$key] ?? 0);
@endphp
<div x-data="{ open: false }" class="relative shrink-0">
    <button type="button" @click="open = !open" @click.outside="open = false" @keydown.escape.window="open = false"
            @class(['inline-flex items-center gap-1.5 rounded-md px-2 py-1 font-semibold text-sm hover:bg-surface-2', 'cursor-default hover:bg-transparent' => $all->count() < 2])
            @disabled($all->count() < 2)>
        @if ($cur)
            @include('partials.dot', ['level' => $cur->status(), 'title' => $cur->last_error])
            {{ $cur->name }}
        @else
            <span class="dot bg-accent"></span> {{ __('All vCenters') }}
        @endif
        @if ($all->count() > 1) <span class="text-muted text-[10px]">▼</span> @endif
    </button>
    @if ($all->count() > 1)
        <div x-show="open" x-cloak x-transition.opacity.duration.100ms
             class="absolute left-0 top-full mt-1 z-50 min-w-72 card shadow-lg py-1 text-xs">
            @foreach ($all as $vc)
                <a href="{{ route($list, ['vc' => $vc->id]) }}"
                   @class(['flex items-center gap-2 px-3 py-1.5 hover:bg-surface-2', 'bg-accent-soft' => $cur?->is($vc)])>
                    @include('partials.dot', ['level' => $vc->status()])
                    <span class="font-medium">{{ $vc->name }}</span>
                    <span class="ml-auto text-muted tabular-nums whitespace-nowrap">
                        {{ $counts($vc)['inv_hosts'] ?? 0 }} {{ __('hosts') }} · {{ $counts($vc)['inv_vms'] ?? 0 }} {{ __('VMs') }}
                    </span>
                </a>
            @endforeach
            <div class="border-t border-line my-1"></div>
            <a href="{{ route($list, ['vc' => 'all']) }}" @class(['flex items-center gap-2 px-3 py-1.5 hover:bg-surface-2', 'bg-accent-soft' => ! $cur])>
                <span class="dot bg-accent"></span>
                <span class="font-medium">{{ __('All vCenters') }}</span>
                <span class="ml-auto text-muted tabular-nums">{{ $sum('inv_hosts') }} {{ __('hosts') }} · {{ $sum('inv_vms') }} {{ __('VMs') }}</span>
            </a>
        </div>
    @endif
</div>

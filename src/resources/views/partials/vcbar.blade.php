{{--
    Second header row on vCenter-scoped pages: the sections of the selected vCenter (or all vCenters) as tabs,
    and on the right the vCenter's key facts, last update and Collect now. Params: $sections, $vcKey (from the layout).
--}}
@php
    $scope = app(\App\Support\VcScope::class);
    $all = $scope->vcenters();
    $cur = $scope->current();
    $count = fn ($key) => $scope->inScope()->sum(fn ($vc) => $vc->inventory['counts'][$key] ?? 0);
@endphp
<div class="border-t border-line bg-surface-2/70">
    <div class="px-4 h-9 flex items-center gap-3 text-xs min-w-0">
        <nav class="flex items-center gap-0.5 min-w-0 overflow-x-auto">
            @foreach ($sections as [$route, $label, $countKey, $icon])
                <a href="{{ route($route, ['vc' => $vcKey]) }}" @class(['vc-tab', 'active' => request()->routeIs($route, $route.'.*')])>
                    @include('partials.icon', ['name' => $icon, 'class' => 'vc-tab-icon'])
                    {{ $label }}
                    @if ($countKey) <span class="vc-tab-count">{{ $count($countKey) }}</span> @endif
                </a>
            @endforeach
        </nav>

        <div class="ml-auto shrink-0 flex items-center gap-3 min-w-0">
            <div class="hidden xl:flex items-center gap-x-3 text-muted whitespace-nowrap">
                {{-- version, build and IP sit next to the vCenter's name in the top row --}}
                @if ($cur)
                    @if ($cur->ip && $cur->ip !== $cur->host) <span class="font-mono">{{ $cur->host }}</span> @endif
                    @if ($cur->location) <span class="badge">{{ $cur->location }}</span> @endif
                @endif
            </div>
            @if ($cur)
                {{-- last update sits right next to the Collect now button --}}
                @if ($cur->last_error)
                    <span class="text-crit italic truncate max-w-64" title="{{ $cur->last_error }}">{{ __('Last update failed') }}</span>
                @else
                    <span class="text-muted italic whitespace-nowrap">{{ __('Last update') }} {{ Fmt::ago($cur->last_success_at) }}</span>
                @endif
                @include('partials.collect-button', ['vc' => $cur])
            @endif
        </div>
    </div>
</div>

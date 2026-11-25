{{-- Page title + quick filter + export (the vCenter is chosen in the vCenter bar).
     Params: $title, $count, $table (id to filter), $export (id of the table to export; default $table),
     $localToggle (id of a table whose data-local rows get a "Show local datastores" checkbox) --}}
@php
    $export ??= $table ?? null;
    $cur = app(\App\Support\VcScope::class)->current();
    $fileName = Str::slug($title).'-'.($cur ? Str::slug($cur->name) : 'all-vcenters');
@endphp
<div class="flex flex-wrap items-center gap-2 mb-3">
    <h1 class="text-base font-semibold">{{ $title }}</h1>
    @isset($count) <span class="badge" @isset($table) data-count-for="{{ $table }}" @endisset>{{ $count }}</span> @endisset
    {{ $slot ?? '' }}
    <div class="ml-auto flex items-center gap-2">
        @isset($localToggle)
            {{-- off by default: local (single-host) datastores are hidden until asked for --}}
            <label class="cf-check text-xs mr-1">
                <input type="checkbox" data-show-local="{{ $localToggle }}"> {{ __('Show local datastores') }}
            </label>
        @endisset
        @isset($table)
            <input type="search" data-filter="{{ $table }}" class="input py-1 w-56" placeholder="{{ __('Filter…') }}" autocomplete="off">
        @endisset
        @if ($export)
            @include('partials.export-button', ['table' => $export, 'name' => $fileName])
        @endif
    </div>
</div>

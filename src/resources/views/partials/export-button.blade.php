{{-- Export of a table as shown (filters and sort applied). Params: $table (id), $name (file name without extension) --}}
<div x-data="{ open: false }" class="relative">
    <button type="button" class="btn btn-sm" @click="open = !open" @click.outside="open = false" @keydown.escape.window="open = false" title="{{ __('Export the rows shown') }}">
        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
        {{ __('Export') }}
    </button>
    <div x-show="open" x-cloak x-transition.opacity.duration.100ms class="absolute right-0 top-full mt-1 z-50 w-48 card shadow-lg py-1 text-sm">
        <button type="button" class="w-full text-left px-3 py-1.5 hover:bg-surface-2 cursor-pointer" data-export="{{ $table }}" data-format="xlsx" data-name="{{ $name }}" @click="open = false">
            Excel <span class="text-muted text-xs">(.xlsx)</span>
        </button>
        <button type="button" class="w-full text-left px-3 py-1.5 hover:bg-surface-2 cursor-pointer" data-export="{{ $table }}" data-format="csv" data-name="{{ $name }}" @click="open = false">
            CSV <span class="text-muted text-xs">(; {{ __('separated') }})</span>
        </button>
    </div>
</div>

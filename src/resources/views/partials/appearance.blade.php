{{-- Palette + light / dark / system picker --}}
<div x-data="appearance" class="relative">
    <button type="button" @click="open = !open" @click.outside="open = false" class="btn border-transparent px-2" title="{{ __('Appearance') }}">
        <span class="size-4 rounded-full border border-line" style="background: var(--color-accent)"></span>
        <svg class="size-3 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
    </button>
    <div x-show="open" x-cloak x-transition.opacity class="absolute right-0 mt-1 w-56 card p-3 shadow-lg z-30 space-y-3">
        <div>
            <div class="label">{{ __('Mode') }}</div>
            <div class="grid grid-cols-3 rounded-md border border-line p-0.5">
                @foreach (['light' => 'M12 4V2m0 20v-2m8-8h2M2 12h2m13.66-5.66 1.41-1.41M4.93 19.07l1.41-1.41m0-11.32L4.93 4.93m14.14 14.14-1.41-1.41M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
                           'dark' => 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z',
                           'system' => 'M4 5h16v11H4zM8 20h8m-4-4v4'] as $m => $path)
                    <button type="button" @click="setMode('{{ $m }}')" title="{{ __(ucfirst($m)) }}"
                            class="flex justify-center rounded py-1 text-muted hover:text-fg cursor-pointer"
                            :class="mode === '{{ $m }}' && 'bg-surface-2 text-fg'">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}"/></svg>
                    </button>
                @endforeach
            </div>
        </div>
        <div>
            <div class="label">{{ __('Theme') }}</div>
            <div class="grid grid-cols-4 gap-1.5">
                @foreach (config('nxtvm.themes') as $name => $swatch)
                    <button type="button" @click="setTheme('{{ $name }}')" title="{{ __(ucfirst($name)) }}"
                            class="flex flex-col items-center gap-0.5 rounded p-1 hover:bg-surface-2 cursor-pointer"
                            :class="theme === '{{ $name }}' && 'ring-2 ring-accent'">
                        <span class="size-5 rounded-full" style="background: {{ $swatch }}"></span>
                        <span class="text-[10px] text-muted">{{ __(ucfirst($name)) }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>
</div>

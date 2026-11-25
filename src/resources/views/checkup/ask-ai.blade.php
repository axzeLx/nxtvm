{{--
    "Ask AI" magic button + popover on a Checkup finding card. Params: $prompt (with {language}), $title.
    The assistant opens in the user's own browser session; see resources/js/app.js (askAi).
--}}
<div x-data="askAi(@js($prompt), {{ ($row ?? false) ? 'true' : 'false' }})" class="relative inline-block"
     data-l-copied="{{ __('Question copied to the clipboard.') }}"
     data-l-copy-failed="{{ __('Copying failed — select the text and copy it manually.') }}"
     data-l-paste="{{ __('Question copied — paste it into :name with Ctrl+V.') }}"
     @keydown.escape.window="open = false">
    @if ($row ?? false)
        {{-- quiet variant for table rows: only the sparkle is coloured --}}
        <button type="button" class="btn-ai" @click="toggle()" x-ref="trigger" :aria-expanded="open.toString()" aria-haspopup="dialog">
            @include('partials.ai-sparkle', ['class' => 'size-3.5'])
            {{ __('Ask AI') }}
        </button>
    @else
        <button type="button" class="btn-magic" @click="toggle()" x-ref="trigger" :aria-expanded="open.toString()" aria-haspopup="dialog">
            @include('partials.ai-sparkle', ['class' => 'size-3.5', 'white' => true])
            {{ __('Ask AI') }}
        </button>
    @endif

    <div x-show="open" x-cloak x-transition.origin.top.right @click.outside="open = false" role="dialog"
         x-ref="panel" :class="fixed ? 'fixed max-h-[calc(100vh-2rem)] overflow-y-auto' : 'absolute right-0 top-full mt-2'" :style="fixed && pos"
         class="z-40 w-[26rem] max-w-[calc(100vw-2rem)] rounded-xl p-px magic-ring shadow-2xl">
        <div class="rounded-[11px] bg-surface p-3 space-y-3 text-left font-normal">
            <div class="flex items-start gap-2">
                @include('partials.ai-sparkle', ['class' => 'size-5 mt-0.5'])
                <div class="min-w-0">
                    <div class="text-sm font-semibold">{{ __('Ask an AI assistant') }}</div>
                    <div class="text-xs text-muted truncate">{{ $title }}</div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <template x-for="p in providers" :key="p.label">
                    <button type="button" @click="ask(p)"
                            class="btn justify-start gap-2 hover:border-accent/60">
                        <span class="inline-flex size-5 items-center justify-center rounded-md magic-ring text-[10px] font-bold text-white" x-text="p.label.slice(0, 1)"></span>
                        <span x-text="p.label"></span>
                        <svg class="ml-auto size-3.5 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg>
                    </button>
                </template>
                <button type="button" @click="copy()" class="btn justify-start gap-2">
                    <svg class="size-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                    {{ __('Copy question') }}
                </button>
            </div>

            <div>
                <button type="button" @click="showText = !showText" class="text-xs text-accent hover:underline cursor-pointer">
                    <span x-text="showText ? @js(__('Hide question')) : @js(__('Show / edit question'))"></span>
                </button>
                <textarea x-show="showText" x-cloak x-model="text" rows="10" class="input mt-1.5 font-mono text-[11px] leading-snug"></textarea>
            </div>

            <p class="text-[11px] text-muted flex items-center gap-1.5">
                <template x-if="window.nxtvmAi?.anonymize">
                    <span class="inline-flex items-center gap-1 text-ok"><svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.3 7.5 9.5 4.3-1.2 7.5-4.9 7.5-9.5V6z"/></svg>{{ __('Names anonymized') }}</span>
                </template>
                <template x-if="! window.nxtvmAi?.anonymize">
                    <span class="text-warn">{{ __('Names are included') }}</span>
                </template>
                <span>· {{ __('opens in your own account') }}</span>
            </p>
        </div>
    </div>

    {{-- toast --}}
    <div x-show="toast" x-cloak x-transition.opacity class="fixed bottom-5 right-5 z-50 rounded-lg p-px magic-ring shadow-xl">
        <div class="rounded-[7px] bg-surface px-3 py-2 text-sm flex items-center gap-2">
            @include('partials.ai-sparkle', ['class' => 'size-4'])
            <span x-text="toast"></span>
        </div>
    </div>
</div>

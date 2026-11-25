{{-- "Collect now" for one vCenter (admins only). Shows queued / collecting / done and reloads the page when finished. Params: $vc --}}
@if (auth()->user()->isAdmin() && $vc->is_enabled)
    <span x-data="vcCollect(@js(route('settings.vcenters.collect', $vc)), @js(route('settings.vcenters.collect-status')), {{ $vc->id }}, @js($vc->collectState()))"
          :data-collect-state="state"
          data-l-idle="{{ __('Collect now') }}" data-l-queued="{{ __('Queued…') }}" data-l-running="{{ __('Collecting…') }}"
          data-l-done="{{ __('Updated') }}" data-l-failed="{{ __('Failed') }}"
          class="inline-flex align-middle">
        <button type="button" class="btn btn-sm" data-collect-button @click="run()" :disabled="busy" :title="error || ''"
                :class="{ '!text-ok': state === 'done', '!text-crit': state === 'failed' }">
            <svg class="size-3.5" :class="busy && 'animate-spin'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>
            <span x-text="label">{{ __('Collect now') }}</span>
        </button>
    </span>
@endif

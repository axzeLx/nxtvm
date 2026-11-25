{{-- How to get a license: machine code → supplier (NXTVM_LICENSE_CONTACT) → key into Settings → License.
     Used on the License settings page and on the Checkup activation page. --}}
@php
    $code = app(\App\Support\Modules::class)->machineCode();
    $contact = config('nxtvm.license_contact');
    $isMail = $contact && filter_var($contact, FILTER_VALIDATE_EMAIL);
@endphp
<ol class="license-steps">
    <li>
        <span class="license-step-no">1</span>
        <div class="min-w-0">
            <p class="font-medium">{{ __('Copy the machine code') }}</p>
            @if ($code)
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <span class="font-mono text-base tracking-wider select-all">{{ $code }}</span>
                    <button type="button" class="btn btn-sm" data-copy="{{ $code }}">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                        <span data-copy-label>{{ __('Copy') }}</span>
                    </button>
                </div>
            @else
                <p class="mt-1 text-crit">{{ __('Not available: the containers need the host machine id (see docker-compose.yml).') }}</p>
            @endif
        </div>
    </li>
    <li>
        <span class="license-step-no">2</span>
        <div class="min-w-0">
            <p class="font-medium">{{ __('Send it to') }}
                @if ($isMail)
                    <a href="mailto:{{ $contact }}?subject={{ rawurlencode('nxtVM license · '.$code) }}&body={{ rawurlencode('Machine code: '.$code."\n") }}" class="text-accent hover:underline">{{ $contact }}</a>
                @elseif ($contact)
                    {{ $contact }}
                @else
                    {{ __('your nxtVM supplier') }}
                @endif
            </p>
            <p class="text-muted">{{ __('The license key is issued for this machine only.') }}</p>
        </div>
    </li>
    <li>
        <span class="license-step-no">3</span>
        <div class="min-w-0">
            <p class="font-medium">{{ __('Paste the key you receive') }}</p>
            <p class="text-muted">{{ __('Settings → License → License key, then Activate.') }}</p>
        </div>
    </li>
</ol>

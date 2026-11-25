{{-- Logo: server with a lightning bolt + product name. Optional $logoIcon (icon size classes, default size-6) and
     $logoText (name classes) — distinct names: parent views' variables are visible here.
     The bolt is cut out of the server outline with a mask (clean on any background); the mask id is unique per use. --}}
@php $logoMask = 'nxlogo-'.Str::random(6); @endphp
<span class="inline-flex items-center gap-2 font-semibold tracking-tight {{ $logoText ?? '' }}">
    <svg class="{{ $logoIcon ?? 'size-6' }} text-accent shrink-0" viewBox="0 0 24 24" aria-hidden="true">
        <mask id="{{ $logoMask }}">
            <rect width="24" height="24" fill="#fff"/>
            <path d="M13.6 0.8 8 12.4h4.2L10.4 23.2 16.6 10.6h-4.3l2.6-9.8z" fill="#000" stroke="#000" stroke-width="2.4" stroke-linejoin="round"/>
        </mask>
        <g mask="url(#{{ $logoMask }})" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="18" height="7" rx="1.5"/>
            <rect x="3" y="14" width="18" height="7" rx="1.5"/>
            <path d="M6.5 6.5h.01M6.5 17.5h.01M17 6.5h1M17 17.5h1"/>
        </g>
        <path d="M13.6 0.8 8 12.4h4.2L10.4 23.2 16.6 10.6h-4.3l2.6-9.8z" fill="#f59e0b"/>
    </svg>
    <span>{{ config('app.name') }}</span>
</span>

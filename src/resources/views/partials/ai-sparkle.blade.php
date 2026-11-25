{{-- Sparkles ("Ask AI"). Params: optional $class; $white = true for use on the gradient button --}}
@php $gid = 'spk-'.Str::random(6); @endphp
<svg class="{{ $class ?? 'size-4' }} shrink-0" viewBox="0 0 24 24" aria-hidden="true">
    @unless ($white ?? false)
        <defs><linearGradient id="{{ $gid }}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f59e0b"/><stop offset=".5" stop-color="#ec4899"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient></defs>
    @endunless
    <g fill="{{ ($white ?? false) ? '#fff' : 'url(#'.$gid.')' }}">
        <path d="M10 2.5c.4 3.9 2.6 6.1 6.5 6.5-3.9.4-6.1 2.6-6.5 6.5-.4-3.9-2.6-6.1-6.5-6.5 3.9-.4 6.1-2.6 6.5-6.5z"/>
        <path d="M18 13c.25 2.2 1.3 3.25 3.5 3.5-2.2.25-3.25 1.3-3.5 3.5-.25-2.2-1.3-3.25-3.5-3.5 2.2-.25 3.25-1.3 3.5-3.5z"/>
        <path d="M5 16.5c.15 1.3.7 1.85 2 2-1.3.15-1.85.7-2 2-.15-1.3-.7-1.85-2-2 1.3-.15 1.85-.7 2-2z" opacity=".8"/>
    </g>
</svg>

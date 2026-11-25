{{-- Colourful Checkup shield (same in every theme). Param: optional $class --}}
@php $gid = 'chk-'.Str::random(6); @endphp
<svg class="{{ $class ?? 'size-[18px]' }} shrink-0" viewBox="0 0 24 24" aria-hidden="true">
    <defs><linearGradient id="{{ $gid }}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f59e0b"/><stop offset=".5" stop-color="#ec4899"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient></defs>
    <path d="M12 2.5 4 5.7v5.8c0 4.9 3.4 8.9 8 10 4.6-1.1 8-5.1 8-10V5.7z" fill="url(#{{ $gid }})"/>
    <path d="m8.6 12.1 2.3 2.3 4.6-4.8" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>

{{-- VM power state icon: running ▶ green, stopped ■ red, suspended ❚❚ amber. Params: $state, $class (size, default size-4) --}}
@php $power = ['poweredOn' => ['text-ok', 'M5 3.5v9l8-4.5z'], 'poweredOff' => ['text-crit', 'M4 4h8v8H4z'], 'suspended' => ['text-warn', 'M4 3.5h3v9H4zM9 3.5h3v9H9z']][$state] ?? null; @endphp
@if ($power)
    <svg class="{{ $class ?? 'size-4' }} shrink-0 {{ $power[0] }}" viewBox="0 0 16 16" fill="currentColor" role="img" aria-label="{{ Fmt::state($state) }}"><title>{{ Fmt::state($state) }}</title><path d="{{ $power[1] }}"/></svg>
@else
    <span class="badge">{{ Fmt::state($state) }}</span>
@endif

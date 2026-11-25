{{-- Compact usage bar. $pct (0-100|null), optional $warn/$crit thresholds, $label --}}
@php
    $lvl = Fmt::pctLevel($pct, $warn ?? 80, $crit ?? 90);
@endphp
<div class="flex items-center gap-1.5 min-w-24" title="{{ $title ?? '' }}">
    <div class="h-1.5 flex-1 rounded-full bg-surface-2 overflow-hidden">
        <div @class(['h-full rounded-full', 'bg-ok' => $lvl === 'ok', 'bg-warn' => $lvl === 'warn', 'bg-crit' => $lvl === 'crit', 'bg-unknown' => $lvl === 'unknown'])
             style="width: {{ min(100, max(0, $pct ?? 0)) }}%"></div>
    </div>
    <span class="text-[11px] tabular-nums text-muted w-8 text-right">{{ $pct === null ? '—' : round($pct).'%' }}</span>
</div>

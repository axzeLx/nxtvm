{{-- Rendered inside an x-data="vcTest(...)" scope --}}
<template x-if="result">
    <div class="rounded-md border px-3 py-2 text-xs space-y-1"
         :class="result.ok ? 'border-ok/30 bg-ok/10' : 'border-crit/30 bg-crit/10'">
        <div class="font-medium break-words" :class="result.ok ? 'text-ok' : 'text-crit'">
            <span x-text="result.ok ? '✓' : '✗'"></span> <span x-text="result.message"></span>
        </div>
        <template x-for="(v, k) in (result.details || {})" :key="k">
            <div><span class="text-muted" x-text="k + ':'"></span> <span x-text="v"></span></div>
        </template>
        <template x-for="w in (result.warnings || [])" :key="w">
            <div class="text-warn">⚠ <span x-text="w"></span></div>
        </template>
    </div>
</template>

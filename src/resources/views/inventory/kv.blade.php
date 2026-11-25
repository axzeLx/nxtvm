{{-- Key / value card. $title, $rows: [label => value(html-safe string or null)] --}}
<div class="card">
    <div class="card-h"><h2 class="card-t">{{ $title }}</h2></div>
    <dl class="grid grid-cols-[minmax(7rem,auto)_1fr] text-[13px]">
        @foreach ($rows as $label => $value)
            <dt class="px-3 py-1 text-muted border-b border-line">{{ $label }}</dt>
            <dd class="px-3 py-1 border-b border-line break-words">{!! $value === null || $value === '' ? '<span class="text-muted">—</span>' : $value !!}</dd>
        @endforeach
    </dl>
</div>

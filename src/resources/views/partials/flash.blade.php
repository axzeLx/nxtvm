@if (session('status'))
    <div class="mb-4 rounded-md border border-ok/30 bg-ok/10 px-3 py-2 text-sm text-ok break-words">{{ session('status') }}</div>
@endif
@foreach (['user', 'vcenter', 'license'] as $key)
    @if ($errors->has($key))
        <div class="mb-4 rounded-md border border-crit/30 bg-crit/10 px-3 py-2 text-sm text-crit break-words">{{ $errors->first($key) }}</div>
    @endif
@endforeach

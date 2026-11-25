@extends('layouts.settings', ['title' => __('Audit log')])

@section('settings')
    <div class="card">
        <div class="card-h">
            <div>
                <h2 class="card-t">{{ __('Audit log') }}</h2>
                <p class="text-xs text-muted">{{ __('Sign-ins and changes made in this application. Kept for :days days.', ['days' => config('nxtvm.audit_days')]) }}</p>
            </div>
            <form method="GET"><input name="q" value="{{ request('q') }}" class="input py-1 w-48" placeholder="{{ __('Search') }}"></form>
        </div>
        @if ($logs->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-muted">{{ __('Nothing recorded yet.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>{{ __('Time') }}</th><th>{{ __('User') }}</th><th>{{ __('Action') }}</th><th>{{ __('Subject') }}</th><th>{{ __('Details') }}</th><th>{{ __('IP') }}</th></tr></thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td class="text-xs text-muted whitespace-nowrap tabular-nums">{{ Fmt::time($log->created_at) }}</td>
                                <td class="font-mono text-xs">{{ $log->username ?? '—' }}</td>
                                <td><span @class(['badge font-mono', 'badge-crit' => str_contains($log->action, 'delete') || $log->action === 'login.failed', 'badge-accent' => str_contains($log->action, 'create')])>{{ $log->action }}</span></td>
                                <td class="text-sm">{{ $log->subject }}</td>
                                <td class="text-xs text-muted max-w-md">
                                    @foreach ($log->details ?? [] as $k => $v)
                                        <div class="break-all"><span class="font-mono">{{ $k }}</span>:
                                            @if (is_array($v) && array_key_exists('to', $v))
                                                {{ json_encode($v['from']) }} → {{ json_encode($v['to']) }}
                                            @else
                                                {{ is_scalar($v) ? Str::limit((string) $v, 80) : json_encode($v) }}
                                            @endif
                                        </div>
                                    @endforeach
                                </td>
                                <td class="font-mono text-xs text-muted">{{ $log->ip }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
@endsection

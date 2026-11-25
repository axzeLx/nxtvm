@extends('layouts.settings', ['title' => __('vCenters')])

@section('settings')
    <div class="card">
        <div class="card-h">
            <div>
                <h2 class="card-t">{{ __('vCenters') }}</h2>
                <p class="text-xs text-muted">{{ __('Connections are read-only. Passwords are stored encrypted. The order here (▲▼) is used on the dashboard and in vCenter selectors.') }}</p>
            </div>
            <div class="flex items-center gap-2">
                @if ($vcenters->where('is_enabled', true)->count() > 1)
                    <button type="button" class="btn btn-sm" data-collect-all>{{ __('Collect all') }}</button>
                @endif
                <a href="{{ route('settings.vcenters.create') }}" class="btn btn-primary btn-sm">{{ __('Add vCenter') }}</a>
            </div>
        </div>
        @if ($vcenters->isEmpty())
            <p class="px-3 py-8 text-center text-sm text-muted">{{ __('No vCenter yet. Add one with a read-only account.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th data-nosort></th>
                            <th data-nosort>{{ __('Name') }}</th>
                            <th data-nosort>{{ __('Address') }}</th>
                            <th data-nosort>{{ __('Version') }}</th>
                            <th class="text-right" data-nosort>{{ __('Interval') }}</th>
                            <th data-nosort>{{ __('Last test') }}</th>
                            <th data-nosort>{{ __('Last update') }}</th>
                            <th data-nosort></th>
                        </tr>
                    </thead>
                    @foreach ($vcenters as $vc)
                        <tbody x-data="vcTest(@js(route('settings.vcenters.test', $vc)))">
                            <tr>
                                <td class="!pr-0 !pl-1.5 w-0 whitespace-nowrap">
                                    @foreach (['up' => '▲', 'down' => '▼'] as $dir => $arrow)
                                        <form method="POST" action="{{ route('settings.vcenters.move', [$vc, 'dir' => $dir]) }}" class="inline">
                                            @csrf
                                            <button class="px-0.5 text-[10px] text-muted hover:text-accent disabled:opacity-20 disabled:cursor-default cursor-pointer"
                                                    title="{{ $dir === 'up' ? __('Move up') : __('Move down') }}"
                                                    @disabled(($dir === 'up' && $loop->parent->first) || ($dir === 'down' && $loop->parent->last))>{{ $arrow }}</button>
                                        </form>
                                    @endforeach
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 font-medium">
                                        @include('partials.dot', ['level' => $vc->status()])
                                        {{ $vc->name }}
                                    </span>
                                    @unless ($vc->is_enabled) <span class="badge ml-1">{{ __('Disabled') }}</span> @endunless
                                    @if ($vc->location) <div class="text-[11px] text-muted">{{ $vc->location }}</div> @endif
                                </td>
                                <td class="font-mono text-xs whitespace-nowrap">
                                    {{ $vc->host }}{{ $vc->port !== 443 ? ':'.$vc->port : '' }}
                                    @if ($vc->ip && $vc->ip !== $vc->host) <div class="text-muted">{{ $vc->ip }}</div> @endif
                                    <div class="text-muted">{{ $vc->username }}</div>
                                </td>
                                <td class="text-xs whitespace-nowrap">
                                    @if ($vc->about)
                                        {{ $vc->about['version'] }} <span class="text-muted">· {{ $vc->about['build'] }}</span>
                                    @else — @endif
                                </td>
                                <td class="text-xs text-right tabular-nums">{{ $vc->interval_minutes }} min</td>
                                <td class="text-xs whitespace-nowrap" title="{{ $vc->last_test_message }}">
                                    @if ($vc->last_test_at)
                                        <span @class(['text-ok' => $vc->last_test_ok, 'text-crit' => ! $vc->last_test_ok])>{{ $vc->last_test_ok ? '✓' : '✗' }}</span>
                                        <span class="text-muted">{{ Fmt::ago($vc->last_test_at) }}</span>
                                    @else <span class="text-muted">—</span> @endif
                                </td>
                                <td class="text-xs whitespace-nowrap">
                                    <span class="text-muted">{{ $vc->last_success_at ? Fmt::ago($vc->last_success_at) : '—' }}</span>
                                    @if ($vc->collect_ms) <span class="text-muted">· {{ round($vc->collect_ms / 1000, 1) }} s</span> @endif
                                    @if ($vc->last_error) <div class="text-crit max-w-56 truncate" title="{{ $vc->last_error }}">{{ $vc->last_error }}</div> @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                    @include('partials.collect-button', ['vc' => $vc])
                                    <button type="button" class="btn btn-sm" @click="run()" :disabled="busy">
                                        <span x-show="!busy">{{ __('Test') }}</span><span x-show="busy" x-cloak>{{ __('Testing…') }}</span>
                                    </button>
                                    <a href="{{ route('settings.vcenters.edit', $vc) }}" class="btn btn-sm">{{ __('Edit') }}</a>
                                    <form method="POST" action="{{ route('settings.vcenters.destroy', $vc) }}" class="contents" onsubmit="return confirm(@js(__('Delete this vCenter and its inventory data?')))">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger">{{ __('Delete') }}</button>
                                    </form>
                                    </div>
                                </td>
                            </tr>
                            <tr x-show="result" x-cloak>
                                <td colspan="8" class="bg-surface-2/40">@include('settings.vcenters.test-result')</td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        @endif
    </div>
@endsection

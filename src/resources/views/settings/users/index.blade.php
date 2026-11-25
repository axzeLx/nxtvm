@extends('layouts.settings', ['title' => __('Users')])

@section('settings')
    <div class="card">
        <div class="card-h">
            <h2 class="card-t">{{ __('Users') }}</h2>
            <a href="{{ route('settings.users.create') }}" class="btn btn-primary btn-sm">{{ __('Add user') }}</a>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('Username') }}</th>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Role') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Last sign-in') }}</th>
                        <th data-nosort></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $u)
                        <tr>
                            <td class="font-mono text-xs">{{ $u->username }}</td>
                            <td>{{ $u->name }}</td>
                            <td><span @class(['badge', 'badge-accent' => $u->isAdmin()])>{{ __(ucfirst($u->role)) }}</span></td>
                            <td>
                                @if ($u->is_active)
                                    <span class="badge badge-ok">{{ __('Active') }}</span>
                                @else
                                    <span class="badge">{{ __('Disabled') }}</span>
                                @endif
                            </td>
                            <td class="text-muted">{{ $u->last_login_at?->timezone(config('app.display_timezone'))->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('settings.users.edit', $u) }}" class="btn btn-sm">{{ __('Edit') }}</a>
                                @unless ($u->is(auth()->user()))
                                    <form method="POST" action="{{ route('settings.users.destroy', $u) }}" class="inline" onsubmit="return confirm(@js(__('Delete this user?')))">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger">{{ __('Delete') }}</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection

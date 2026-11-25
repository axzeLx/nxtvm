@extends('layouts.settings', ['title' => $user->exists ? __('Edit user') : __('Add user')])

@section('settings')
    <form method="POST" action="{{ $user->exists ? route('settings.users.update', $user) : route('settings.users.store') }}" class="card max-w-2xl">
        @csrf
        @if ($user->exists) @method('PUT') @endif

        <div class="card-h"><h2 class="card-t">{{ $user->exists ? __('Edit user') : __('Add user') }}</h2></div>

        <div class="p-4 grid sm:grid-cols-2 gap-4">
            <div>
                <label class="label" for="username">{{ __('Username') }}</label>
                <input id="username" name="username" class="input font-mono" value="{{ old('username', $user->username) }}" required>
                @error('username') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="name">{{ __('Name') }}</label>
                <input id="name" name="name" class="input" value="{{ old('name', $user->name) }}" required>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="email">{{ __('Email') }}</label>
                <input id="email" name="email" type="email" class="input" value="{{ old('email', $user->email) }}">
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="role">{{ __('Role') }}</label>
                <select id="role" name="role" class="input">
                    @foreach (\App\Models\User::ROLES as $role)
                        <option value="{{ $role }}" @selected(old('role', $user->role) === $role)>{{ __(ucfirst($role)) }}</option>
                    @endforeach
                </select>
                @error('role') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="password">{{ $user->exists ? __('New password (leave empty to keep)') : __('Password') }}</label>
                <input id="password" name="password" type="password" class="input" autocomplete="new-password" @required(! $user->exists)>
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="password_confirmation">{{ __('Repeat password') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="input" autocomplete="new-password">
            </div>
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input type="checkbox" name="is_active" value="1" class="accent-[var(--color-accent)]" @checked(old('is_active', $user->is_active))>
                {{ __('Active (can sign in)') }}
            </label>
        </div>

        <div class="px-4 py-3 border-t border-line flex justify-end gap-2">
            <a href="{{ route('settings.users.index') }}" class="btn">{{ __('Cancel') }}</a>
            <button class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </form>
@endsection

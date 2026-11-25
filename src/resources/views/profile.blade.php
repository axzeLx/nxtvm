@extends('layouts.app', ['title' => __('Profile')])

@section('content')
    <div class="max-w-xl">
        <h1 class="text-lg font-semibold mb-5">{{ __('Profile') }}</h1>
        @include('partials.flash')

        <form method="POST" action="{{ route('profile') }}" class="card p-6 space-y-4">
            @csrf @method('PUT')
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">{{ __('Username') }}</label>
                    <input class="input bg-surface-2" value="{{ $user->username }}" disabled>
                </div>
                <div>
                    <label class="label">{{ __('Role') }}</label>
                    <input class="input bg-surface-2" value="{{ __(ucfirst($user->role)) }}" disabled>
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
            </div>

            <div class="border-t border-line pt-4 grid sm:grid-cols-3 gap-4">
                <div>
                    <label class="label" for="current_password">{{ __('Current password') }}</label>
                    <input id="current_password" name="current_password" type="password" class="input" autocomplete="current-password">
                    @error('current_password') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="password">{{ __('New password') }}</label>
                    <input id="password" name="password" type="password" class="input" autocomplete="new-password">
                    @error('password') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="password_confirmation">{{ __('Repeat') }}</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" class="input" autocomplete="new-password">
                </div>
            </div>

            <div class="flex justify-end"><button class="btn btn-primary">{{ __('Save') }}</button></div>
        </form>
    </div>
@endsection

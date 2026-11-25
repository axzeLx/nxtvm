<!DOCTYPE html>
@include('partials.html-open')
<head>
    @include('partials.head', ['title' => __('Sign in')])
</head>
<body class="min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex items-center justify-between">
            <span class="text-lg">@include('partials.logo')</span>
            @include('partials.appearance')
        </div>

        <form method="POST" action="{{ route('login') }}" class="card p-6 space-y-4">
            @csrf
            <div>
                <h1 class="text-base font-semibold">{{ __('Sign in') }}</h1>
                <p class="text-sm text-muted">{{ __('vCenter inventory & checkup') }}</p>
            </div>

            <div>
                <label class="label" for="username">{{ __('Username') }}</label>
                <input id="username" name="username" value="{{ old('username') }}" class="input" autocomplete="username" autofocus required>
                @error('username') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="password">{{ __('Password') }}</label>
                <input id="password" type="password" name="password" class="input" autocomplete="current-password" required>
            </div>

            <label class="flex items-center gap-2 text-sm text-muted">
                <input type="checkbox" name="remember" class="accent-[var(--color-accent)]"> {{ __('Remember me') }}
            </label>

            <button class="btn btn-primary w-full">{{ __('Sign in') }}</button>
        </form>
    </div>
</body>
</html>

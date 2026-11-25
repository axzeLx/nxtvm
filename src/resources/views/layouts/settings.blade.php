@extends('layouts.app')

@section('content')
    <div class="flex flex-col md:flex-row gap-5">
        {{-- stays in view on long settings pages (below the 48px top bar; settings pages have no vCenter bar) --}}
        <aside class="md:w-44 shrink-0 md:sticky md:top-16 md:self-start">
            <h1 class="text-base font-semibold mb-2">{{ __('Settings') }}</h1>
            <nav class="flex md:flex-col gap-0.5">
                <a href="{{ route('settings.vcenters.index') }}" @class(['nav-link', 'active' => request()->routeIs('settings.vcenters.*')])>{{ __('vCenters') }}</a>
                <a href="{{ route('settings.users.index') }}" @class(['nav-link', 'active' => request()->routeIs('settings.users.*')])>{{ __('Users') }}</a>
                <a href="{{ route('settings.checkup') }}" @class(['nav-link', 'active' => request()->routeIs('settings.checkup')])>{{ __('Checkup') }}</a>
                <a href="{{ route('settings.ai') }}" @class(['nav-link', 'active' => request()->routeIs('settings.ai')])>{{ __('AI assistants') }}</a>
                <a href="{{ route('settings.license') }}" @class(['nav-link', 'active' => request()->routeIs('settings.license*')])>{{ __('License') }}</a>
                <a href="{{ route('settings.audit') }}" @class(['nav-link', 'active' => request()->routeIs('settings.audit')])>{{ __('Audit log') }}</a>
            </nav>
        </aside>
        <section class="flex-1 min-w-0">
            @include('partials.flash')
            @yield('settings')
        </section>
    </div>
@endsection

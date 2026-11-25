<!DOCTYPE html>
@include('partials.html-open')
<head>
    @include('partials.head')
</head>
<body class="min-h-screen">
    @php
        // vCenter sections: shown as tabs under the selected vCenter (the vCenter is the parent in the breadcrumb).
        // [route, label, inventory count key, icon]
        $sections = [
            ['dashboard', __('Overview'), null, 'overview'],
            ['hosts', __('Hosts'), 'inv_hosts', 'server'],
            ['vms', __('VMs'), 'inv_vms', 'vm'],
            ['datastores', __('Datastores'), 'inv_datastores', 'datastore'],
            ['networks', __('Networks'), 'inv_networks', 'network'],
            ['licenses', __('Licenses'), 'inv_licenses', 'key'],
            ['certificates', __('Certificates'), 'inv_certificates', 'certificate'],
            ['components', __('Components'), null, 'package'],
        ];
        $checkupOn = app(\App\Support\Modules::class)->enabled(\App\Support\Modules::CHECKUP);
        // vCenter-scoped pages carry the vCenter switcher and the section tabs; their links keep the selected vCenter in the URL
        $vcPages = ['dashboard', 'hosts', 'hosts.*', 'vms', 'vms.*', 'datastores', 'datastores.*', 'networks', 'networks.*', 'licenses', 'certificates', 'components', 'checkup'];
        $vcBar = request()->routeIs($vcPages) && app(\App\Support\VcScope::class)->vcenters()->isNotEmpty();
        $vcKey = app(\App\Support\VcScope::class)->key();   // every link into /vc/{vc}/… carries the selected vCenter
        // detail page (hosts.show, …): breadcrumb continues with the section and the object's name
        $section = $vcBar && request()->routeIs('*.show') ? collect($sections)->firstWhere(0, Str::before(Route::currentRouteName(), '.show')) : null;
    @endphp
    <header class="sticky top-0 z-40 border-b border-line bg-surface">
        <div class="px-4 h-12 flex items-center gap-5">
            <a href="{{ route('vcenters') }}" class="shrink-0">@include('partials.logo')</a>

            {{-- breadcrumb: vCenters / selected vCenter ▼ / section / object --}}
            <nav class="flex items-center gap-1 min-w-0 text-sm">
                <a href="{{ route('vcenters') }}" @class(['nav-link', 'active' => request()->routeIs('vcenters')])>{{ __('vCenters') }}</a>
                @if (request()->routeIs('vcenters'))
                    {{-- on the vCenters page itself: every vCenter as a menu entry, a step into it --}}
                    <span class="ml-4 flex items-center gap-0.5 min-w-0 overflow-x-auto">
                        @foreach (app(\App\Support\VcScope::class)->vcenters() as $vc)
                            <a href="{{ route('dashboard', ['vc' => $vc->id]) }}" class="nav-link inline-flex items-center gap-1.5" title="{{ $vc->host }}">
                                @include('partials.dot', ['level' => $vc->status(), 'title' => $vc->last_error])
                                {{ $vc->name }}
                            </a>
                        @endforeach
                    </span>
                @endif
                @if ($vcBar)
                    <span class="crumb-sep">/</span>
                    @include('partials.vcswitch')
                    {{-- short facts of the selected vCenter, right after its switcher --}}
                    @php $curVc = app(\App\Support\VcScope::class)->current(); @endphp
                    <span class="hidden md:inline ml-1 text-xs text-muted whitespace-nowrap">
                        @if ($curVc)
                            @if ($curVc->about['version'] ?? null) vCenter <span class="text-fg">{{ $curVc->about['version'] }}</span> @endif
                            @if ($curVc->about['build'] ?? null) · build {{ $curVc->about['build'] }} @endif
                            @if ($curVc->about['version'] ?? $curVc->about['build'] ?? null) – @endif
                            <span class="font-mono">{{ $curVc->ip ?: $curVc->host }}</span>
                        @else
                            {{ trans_choice(':n vCenter|:n vCenters', app(\App\Support\VcScope::class)->vcenters()->count(), ['n' => app(\App\Support\VcScope::class)->vcenters()->count()]) }}
                        @endif
                    </span>
                    @if (request()->routeIs('checkup'))
                        {{-- Checkup runs on the selected vCenter: the path says so --}}
                        <span class="crumb-sep">/</span>
                        <span class="px-1 font-medium">{{ __('Checkup') }}</span>
                    @endif
                    @if ($section)
                        <span class="crumb-sep">/</span>
                        <a href="{{ route($section[0], ['vc' => $vcKey]) }}" class="nav-link">{{ $section[1] }}</a>
                        <span class="crumb-sep">/</span>
                        <span class="px-1 font-medium truncate" title="{{ $title ?? '' }}">{{ $title ?? '' }}</span>
                    @endif
                @endif
            </nav>

            <div class="ml-auto flex items-center gap-1 shrink-0">
                {{-- licensed module: always listed, set apart from the inventory pages; locked until a license key enables it --}}
                <a href="{{ route('checkup', ['vc' => $vcKey]) }}" @class(['nav-module', 'active' => request()->routeIs('checkup', 'checkup.*')])>
                    @include('partials.checkup-icon')
                    {{ Str::upper(__('Checkup')) }}
                    @unless ($checkupOn) @include('partials.icon', ['name' => 'lock', 'class' => 'size-3 opacity-70']) @endunless
                </a>
                @if (auth()->user()->isAdmin())
                    <span class="mx-1.5 h-5 w-px bg-line"></span>
                    <a href="{{ route('settings.index') }}" @class(['nav-link inline-flex items-center gap-1.5', 'active' => request()->routeIs('settings.*')])>
                        @include('partials.icon', ['name' => 'gear', 'class' => 'size-4 opacity-70'])
                        {{ __('Settings') }}
                    </a>
                @endif
                <span class="mx-1.5 h-5 w-px bg-line"></span>

                @include('partials.appearance')

                <div x-data="{ open: false }" class="relative">
                    <button type="button" @click="open = !open" @click.outside="open = false" class="btn border-transparent px-2">
                        <span class="inline-flex size-6 items-center justify-center rounded-full bg-accent-soft text-accent text-xs font-semibold">
                            {{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}
                        </span>
                        <span class="hidden sm:inline">{{ auth()->user()->name }}</span>
                    </button>
                    <div x-show="open" x-cloak x-transition.opacity class="absolute right-0 mt-1 w-44 card py-1 shadow-lg z-20">
                        <a href="{{ route('profile') }}" class="block px-3 py-1.5 text-sm hover:bg-surface-2">{{ __('Profile') }}</a>
                        <a href="{{ route('about') }}" class="block px-3 py-1.5 text-sm hover:bg-surface-2">{{ __('About nxtVM') }}</a>
                        <div class="border-t border-line my-1"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="w-full text-left px-3 py-1.5 text-sm hover:bg-surface-2 cursor-pointer">{{ __('Sign out') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        @if ($vcBar) @include('partials.vcbar') @endif
    </header>

    <main @class(['mx-auto px-4 py-4', 'max-w-7xl' => ! ($wide ?? false)])>
        @yield('content')
    </main>
</body>
</html>

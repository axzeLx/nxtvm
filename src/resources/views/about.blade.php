@extends('layouts.app', ['title' => __('About nxtVM')])

{{-- About nxtVM (user menu): hero with the logo over a faint field of scattered boxes, what nxtVM is, highlights,
     edition and support in one line. --}}
@section('content')
    @php
        $edition = $modules->edition();
        $contact = config('nxtvm.license_contact');
        // scattered boxes behind the logo: [x, y, w, h, filled] in a 960 × 300 field
        $boxes = [
            [24, 28, 70, 26, 0], [118, 92, 40, 40, 1], [60, 178, 96, 30, 0], [170, 236, 46, 22, 1], [214, 30, 52, 52, 0],
            [282, 120, 34, 18, 1], [250, 196, 78, 34, 0], [338, 18, 44, 22, 1], [362, 250, 58, 26, 0], [420, 64, 26, 26, 0],
            [500, 22, 62, 22, 0], [560, 244, 40, 40, 1], [612, 34, 34, 34, 1], [640, 178, 86, 30, 0], [700, 96, 46, 20, 0],
            [742, 24, 72, 36, 0], [770, 222, 40, 22, 1], [822, 132, 54, 54, 0], [858, 40, 30, 30, 1], [880, 236, 60, 28, 0],
            [12, 116, 30, 30, 1], [918, 104, 30, 46, 0], [446, 266, 30, 16, 1], [168, 150, 24, 24, 0], [790, 286, 34, 10, 0],
        ];
        $highlights = [
            ['datacenter', __('Every vCenter, one view'), __('All your vCenter Servers side by side, or one at a time.')],
            ['server', __('Inventory at a glance'), __('Hosts, VMs, datastores, networks, licenses and certificates — searchable and exportable.')],
            ['check-shield', __('Checkup'), __('Health checks with a score and clear recommendations.')],
            ['lock', __('Read-only'), __('nxtVM only reads from vCenter. It never changes your environment.')],
        ];
    @endphp

    <div class="card max-w-3xl mx-auto my-4 overflow-hidden">
        {{-- Hero --}}
        <div class="relative px-6 py-14 text-center border-b border-line overflow-hidden">
            <svg class="absolute inset-0 size-full text-accent pointer-events-none" viewBox="0 0 960 300" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
                @foreach ($boxes as $i => [$x, $y, $w, $h, $filled])
                    <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}" rx="5"
                          fill="{{ $filled ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.5"
                          opacity="{{ $filled ? 0.07 + ($i % 3) * 0.02 : 0.14 + ($i % 4) * 0.03 }}"/>
                @endforeach
            </svg>
            {{-- soft fade so the logo stands out from the boxes --}}
            <div class="absolute inset-0 pointer-events-none" style="background: radial-gradient(ellipse 45% 70% at 50% 50%, var(--color-surface) 35%, transparent 100%)"></div>

            <div class="relative">
                @include('partials.logo', ['logoIcon' => 'size-14', 'logoText' => 'text-5xl gap-3'])
                <p class="mt-3 text-base text-muted">{{ __('Your vSphere estate, clearly in view.') }}</p>
                <span class="mt-4 inline-block badge">{{ __('Version') }} {{ config('nxtvm.version') }}</span>
            </div>
        </div>

        {{-- About --}}
        <div class="px-8 py-7 space-y-3 text-[15px] leading-relaxed">
            <p>{{ __('nxtVM brings all of your vCenter Servers together on one screen. It collects the inventory of every vCenter at regular intervals and shows it the way a VMware administrator needs it: searchable, comparable and ready to export.') }}</p>
            <p>{{ __('With the Checkup module nxtVM goes a step further. It reviews the collected inventory against best practices, scores each vCenter and points out what deserves attention — from forgotten snapshots to expiring certificates — together with recommendations on what to do.') }}</p>
            <p class="text-muted">{{ __('Collecting is read-only: nxtVM never changes anything in your vSphere environment.') }}</p>
        </div>

        {{-- Highlights --}}
        <div class="grid gap-px bg-line sm:grid-cols-2 border-y border-line">
            @foreach ($highlights as [$icon, $title, $text])
                <div class="bg-surface px-6 py-4 flex gap-3">
                    <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent">
                        @include('partials.icon', ['name' => $icon, 'class' => 'size-5'])
                    </span>
                    <div>
                        <p class="font-medium">{{ $title }}</p>
                        <p class="text-sm text-muted">{{ $text }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Edition & support --}}
        <div class="px-6 py-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            <span class="inline-flex items-center gap-2">
                <span class="text-muted">{{ __('Edition') }}</span>
                @if ($edition === 'licensed')
                    <span class="badge badge-ok">{{ __('Licensed') }}</span>
                    <span>{{ $modules->license()['lic'] }}</span>
                @elseif ($edition === 'trial')
                    <span class="badge badge-accent">{{ __('Trial') }}</span>
                    <span class="text-muted">{{ trans_choice(':n day left|:n days left', $modules->trialDaysLeft(), ['n' => $modules->trialDaysLeft()]) }}</span>
                @else
                    <span class="badge">{{ __('Free') }}</span>
                @endif
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('settings.license') }}" class="text-xs text-muted hover:text-fg">{{ __('Manage') }} →</a>
                @endif
            </span>
            <span class="ml-auto inline-flex items-center gap-2">
                <span class="text-muted">{{ __('Support & licensing') }}</span>
                @if ($contact && filter_var($contact, FILTER_VALIDATE_EMAIL))
                    <a href="mailto:{{ $contact }}" class="text-accent hover:underline">{{ $contact }}</a>
                @else
                    <span>{{ $contact ?: __('your nxtVM supplier') }}</span>
                @endif
            </span>
        </div>

        <p class="px-6 py-3 border-t border-line bg-surface-2/40 text-center text-xs text-muted">© {{ now()->year }} nxtVM · VMware and vSphere are trademarks of Broadcom Inc.</p>
    </div>
@endsection

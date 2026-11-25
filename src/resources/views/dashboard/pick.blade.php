@extends('layouts.app', ['title' => __('Dashboard')])

@section('content')
    {{-- "All vCenters" is selected: the dashboard shows one vCenter at a time --}}
    <div class="card max-w-xl">
        <div class="card-h"><h2 class="card-t">{{ __('Choose a vCenter') }}</h2></div>
        <p class="px-3 pt-2 text-xs text-muted">{{ __('The dashboard shows one vCenter at a time.') }}</p>
        <div class="py-1">
            @foreach ($vcenters as $vc)
                <a href="{{ route('dashboard', ['vc' => $vc->id]) }}" class="flex items-center gap-2 px-3 py-1.5 hover:bg-surface-2">
                    @include('partials.dot', ['level' => $vc->status()])
                    <span class="font-medium">{{ $vc->name }}</span>
                    <span class="ml-auto text-xs text-muted tabular-nums">
                        {{ $vc->inventory['counts']['inv_hosts'] ?? 0 }} {{ __('hosts') }} · {{ $vc->inventory['counts']['inv_vms'] ?? 0 }} {{ __('VMs') }}
                    </span>
                </a>
            @endforeach
        </div>
    </div>
@endsection

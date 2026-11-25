@extends('layouts.settings', ['title' => $vcenter->exists ? __('Edit vCenter') : __('Add vCenter')])

@section('settings')
    @php $i = config('nxtvm.interval'); @endphp
    <form id="vc-form" method="POST" action="{{ $vcenter->exists ? route('settings.vcenters.update', $vcenter) : route('settings.vcenters.store') }}"
          class="card max-w-2xl" x-data="vcTest(@js(route('settings.vcenters.test-form')), 'vc-form')">
        @csrf
        @if ($vcenter->exists) @method('PUT') <input type="hidden" name="id" value="{{ $vcenter->id }}"> @endif

        <div class="card-h"><h2 class="card-t">{{ $vcenter->exists ? __('Edit vCenter') : __('Add vCenter') }}</h2></div>

        <div class="p-3 grid sm:grid-cols-6 gap-3">
            <div class="sm:col-span-2">
                <label class="label" for="name">{{ __('Name') }}</label>
                <input id="name" name="name" class="input" value="{{ old('name', $vcenter->name) }}" placeholder="VC01" required>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-3">
                <label class="label" for="host">{{ __('Address (FQDN or IP)') }}</label>
                <input id="host" name="host" class="input font-mono" value="{{ old('host', $vcenter->host) }}" placeholder="vcenter.example.com" required>
                @error('host') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="port">{{ __('Port') }}</label>
                <input id="port" name="port" type="number" min="1" max="65535" class="input tabular-nums" value="{{ old('port', $vcenter->port) }}" required>
                @error('port') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-3">
                <label class="label" for="username">{{ __('User') }}</label>
                <input id="username" name="username" class="input font-mono" value="{{ old('username', $vcenter->username) }}" placeholder="svc_nxtvm@vsphere.local" autocomplete="off" required>
                @error('username') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-3">
                <label class="label" for="password">{{ $vcenter->exists ? __('Password (leave empty to keep)') : __('Password') }}</label>
                <input id="password" name="password" type="password" class="input" autocomplete="new-password" @required(! $vcenter->exists)>
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="label" for="interval_minutes">{{ __('Collect every (min)') }}</label>
                <input id="interval_minutes" name="interval_minutes" type="number" min="{{ $i['min'] }}" max="{{ $i['max'] }}" class="input tabular-nums"
                       value="{{ old('interval_minutes', $vcenter->interval_minutes) }}" required>
                @error('interval_minutes') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-4">
                <label class="label" for="location">{{ __('Location (optional)') }}</label>
                <input id="location" name="location" class="input" value="{{ old('location', $vcenter->location) }}" placeholder="{{ __('e.g. Main DC') }}">
                @error('location') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-center gap-2 text-sm sm:col-span-3">
                <input type="checkbox" name="verify_ssl" value="1" class="accent-[var(--color-accent)]" @checked(old('verify_ssl', $vcenter->verify_ssl))>
                {{ __('Verify TLS certificate') }}
            </label>
            <label class="flex items-center gap-2 text-sm sm:col-span-3">
                <input type="checkbox" name="is_enabled" value="1" class="accent-[var(--color-accent)]" @checked(old('is_enabled', $vcenter->is_enabled))>
                {{ __('Enabled (collect inventory)') }}
            </label>
            <p class="hint sm:col-span-6">{{ __('Use an account with the Read-only role, assigned as a global permission and propagated to children.') }}</p>
            <div class="sm:col-span-6">@include('settings.vcenters.test-result')</div>
        </div>

        <div class="px-3 py-2 border-t border-line flex items-center gap-2">
            <button type="button" class="btn" @click="run()" :disabled="busy">
                <span x-show="!busy">{{ __('Test connection') }}</span><span x-show="busy" x-cloak>{{ __('Testing…') }}</span>
            </button>
            <a href="{{ route('settings.vcenters.index') }}" class="btn ml-auto">{{ __('Cancel') }}</a>
            <button class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </form>
@endsection

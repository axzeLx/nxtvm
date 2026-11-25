@extends('layouts.settings', ['title' => __('AI assistants')])

{{-- "Ask AI" on Checkup findings: providers (links), how the window opens, language, anonymization, prompt template. --}}
@section('settings')
    @php
        $providers = old('providers', $ai->providers());
        $raw = $ai->raw();
    @endphp

    <form method="POST" action="{{ route('settings.ai') }}" class="space-y-4 max-w-4xl"
          x-data="{ providers: @js(array_values(array_map(fn ($p) => ['label' => $p['label'] ?? '', 'url' => $p['url'] ?? '', 'mode' => $p['mode'] ?? 'prefill', 'on' => (bool) ($p['on'] ?? false)], $providers))) }">
        @csrf @method('PUT')

        <div class="card">
            <div class="card-h">
                <div>
                    <h2 class="card-t inline-flex items-center gap-2">@include('partials.ai-sparkle', ['class' => 'size-4']) {{ __('Ask AI') }}</h2>
                    <p class="text-xs text-muted">{{ __('A button on every Checkup finding opens an AI assistant with a ready-made question. It opens in the user\'s own browser, with their own login / subscription; nothing is sent from this server.') }}</p>
                </div>
                <label class="inline-flex items-center gap-2 text-sm whitespace-nowrap cursor-pointer">
                    <input type="hidden" name="on" value="0">
                    <input type="checkbox" name="on" value="1" @checked($raw['on'] ?? true) class="size-4 accent-[var(--color-accent)]">
                    {{ __('Enabled') }}
                </label>
            </div>

            <div class="p-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="language">{{ __('Answer language') }}</label>
                    <select id="language" name="language" class="input">
                        @foreach (\App\Support\AiAssist::LANGUAGES as $k => $label)
                            <option value="{{ $k }}" @selected(old('language', $ai->language()) === $k)>{{ __($label) }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-muted">{{ __('The question stays in English; "Please answer in …" is added for other languages.') }}</p>
                </div>
                <div>
                    <label class="label">{{ __('Privacy') }}</label>
                    <label class="flex items-start gap-2 text-sm cursor-pointer">
                        <input type="hidden" name="anonymize" value="0">
                        <input type="checkbox" name="anonymize" value="1" @checked(old('anonymize', $ai->anonymize())) class="mt-0.5 size-4 accent-[var(--color-accent)]">
                        <span>{{ __('Anonymize names') }}<br><span class="text-xs text-muted">{{ __('vCenter, host, VM, datastore, network names and IP addresses are replaced by host-1, vm-1, ip-1 …') }}</span></span>
                    </label>
                </div>
                <div>
                    <label class="label" for="window">{{ __('Open in') }}</label>
                    <div class="flex gap-2">
                        <select id="window" name="window" class="input">
                            <option value="popup" @selected(old('window', $ai->popup() ? 'popup' : 'tab') === 'popup')>{{ __('Side window (right edge of the screen)') }}</option>
                            <option value="tab" @selected(old('window', $ai->popup() ? 'popup' : 'tab') === 'tab')>{{ __('New tab') }}</option>
                        </select>
                        <input type="number" name="width" min="360" max="1200" value="{{ old('width', $ai->width()) }}" class="input w-24" title="{{ __('Window width (px)') }}">
                        <span class="self-center text-xs text-muted">px</span>
                    </div>
                </div>
                <div>
                    <label class="label" for="max_findings">{{ __('Findings included in the question') }}</label>
                    <input id="max_findings" type="number" name="max_findings" min="1" max="50" value="{{ old('max_findings', $ai->maxFindings()) }}" class="input w-24">
                </div>
            </div>
        </div>

        {{-- providers --}}
        <div class="card">
            <div class="card-h">
                <div>
                    <h2 class="card-t">{{ __('Assistants') }}</h2>
                    <p class="text-xs text-muted">{!! __('Disabled assistants get no button. In the link, :p is replaced by the question; without it the question is copied to the clipboard.', ['p' => '<code class="font-mono">{prompt}</code>']) !!}</p>
                </div>
                <button type="button" class="btn btn-sm" @click="providers.push({ label: '', url: 'https://', mode: 'copy', on: true })" x-show="providers.length < 10">{{ __('Add') }}</button>
            </div>
            <table class="table">
                <thead><tr>
                    <th class="w-10" data-nosort>{{ __('On') }}</th>
                    <th class="w-36" data-nosort>{{ __('Name') }}</th>
                    <th data-nosort>{{ __('Link') }}</th>
                    <th class="w-56" data-nosort>{{ __('Question is') }}</th>
                    <th class="w-8" data-nosort></th>
                </tr></thead>
                <tbody>
                    <template x-for="(p, i) in providers" :key="i">
                        <tr>
                            <td>
                                <input type="hidden" :name="`providers[${i}][on]`" :value="p.on ? 1 : 0">
                                <input type="checkbox" x-model="p.on" class="size-4 accent-[var(--color-accent)]">
                            </td>
                            <td><input :name="`providers[${i}][label]`" x-model="p.label" class="input py-1" required maxlength="30"></td>
                            <td><input :name="`providers[${i}][url]`" x-model="p.url" class="input py-1 font-mono text-xs" required></td>
                            <td>
                                <select :name="`providers[${i}][mode]`" x-model="p.mode" class="input py-1">
                                    @foreach (\App\Support\AiAssist::MODES as $k => $label)
                                        <option value="{{ $k }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><button type="button" class="btn btn-danger btn-sm" @click="providers.splice(i, 1)" title="{{ __('Remove') }}">×</button></td>
                        </tr>
                    </template>
                </tbody>
            </table>
            @if ($errors->has('providers.*'))
                <p class="field-error px-4 pb-3">{{ collect($errors->get('providers.*'))->flatten()->first() }}</p>
            @endif
        </div>

        {{-- prompt --}}
        <div class="card" x-data="{ text: @js(old('prompt', $ai->template())), def: @js(\App\Support\AiAssist::DEFAULT_PROMPT) }">
            <div class="card-h">
                <div>
                    <h2 class="card-t">{{ __('Question template') }}</h2>
                    <p class="text-xs text-muted">{{ __('Placeholders') }}: <code class="font-mono">{versions} {title} {severity} {affected} {total} {advice} {findings} {language}</code></p>
                </div>
                <button type="button" class="btn btn-sm" @click="text = def" x-show="text.trim() !== def.trim()">{{ __('Default') }}</button>
            </div>
            <div class="p-4">
                <textarea name="prompt" rows="12" x-model="text" class="input font-mono text-xs"></textarea>
                @error('prompt') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <button name="reset" value="1" formnovalidate class="btn btn-sm ml-auto" onclick="return confirm(@js(__('Reset the AI assistant settings to the defaults?')))">{{ __('Reset to defaults') }}</button>
        </div>
    </form>
@endsection

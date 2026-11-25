<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\AiAssist;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Settings → AI assistants: the "Ask AI" buttons on Checkup findings. */
class AiController extends Controller
{
    public function edit(AiAssist $ai): View
    {
        return view('settings.ai', ['ai' => $ai]);
    }

    public function update(Request $request, AiAssist $ai): RedirectResponse
    {
        if ($request->boolean('reset')) {
            $ai->save([]);
            Audit::log('ai.settings', __('reset to defaults'));

            return redirect()->route('settings.ai')->with('status', __('AI assistant settings reset to defaults.'));
        }

        $data = $request->validate([
            'language' => ['required', Rule::in(array_keys(AiAssist::LANGUAGES))],
            'window' => ['required', 'in:popup,tab'],
            'width' => ['required', 'integer', 'min:360', 'max:1200'],
            'max_findings' => ['required', 'integer', 'min:1', 'max:50'],
            'prompt' => ['nullable', 'string', 'max:5000'],
            'providers' => ['array', 'max:10'],
            'providers.*.label' => ['required', 'string', 'max:30'],
            'providers.*.url' => ['required', 'string', 'max:500', 'starts_with:https://,http://'],
            'providers.*.mode' => ['required', Rule::in(array_keys(AiAssist::MODES))],
        ], [], ['providers.*.url' => __('link'), 'providers.*.label' => __('name')]);

        $providers = [];
        foreach (array_values($data['providers'] ?? []) as $i => $p) {
            $providers[] = [
                'key' => Str::slug($p['label']) ?: 'ai'.$i, 'label' => trim($p['label']), 'url' => trim($p['url']),
                'mode' => $p['mode'], 'on' => (bool) $request->input("providers.$i.on"),
            ];
        }

        $prompt = trim((string) ($data['prompt'] ?? ''));
        $ai->save([
            'on' => $request->boolean('on'),
            'anonymize' => $request->boolean('anonymize'),
            'language' => $data['language'],
            'window' => $data['window'],
            'width' => (int) $data['width'],
            'max_findings' => (int) $data['max_findings'],
            'prompt' => $prompt === trim(AiAssist::DEFAULT_PROMPT) ? '' : $prompt,
            'providers' => $providers,
        ]);
        Audit::log('ai.settings', null, [
            'on' => $ai->enabled(), 'anonymize' => $ai->anonymize(), 'language' => $ai->language(),
            'providers' => collect($providers)->where('on', true)->pluck('label')->join(', ') ?: '—',
        ]);

        return redirect()->route('settings.ai')->with('status', __('AI assistant settings saved.'));
    }
}

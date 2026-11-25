<?php

namespace App\Support;

use App\Models\Inv;
use App\Models\Setting;
use App\Models\VCenter;
use Illuminate\Support\Collection;

/**
 * "Ask AI" on Checkup findings. Nothing is sent from the server: the page builds a prompt and opens the
 * assistant in the user's own browser (their own login / subscription) — prefilled through the URL or copied
 * to the clipboard. Settings (Settings → AI assistants) are stored as JSON in app_settings under "ai".
 */
class AiAssist
{
    private const KEY = 'ai';

    public const LANGUAGES = ['system' => 'System (browser language)', 'en' => 'English', 'tr' => 'Türkçe'];

    public const MODES = ['prefill' => 'Prefilled in the URL', 'copy' => 'Copy to clipboard and open'];

    public const DEFAULT_PROMPT = <<<'TXT'
I administer a VMware vSphere environment ({versions}). An automated health check reported this issue:

Issue: {title}
Severity: {severity} — {affected} of {total} checked objects affected
Check guidance: {advice}

Findings:
{findings}

Briefly explain what this means and the risk, then give the concrete steps to fix it (vSphere Client, and esxcli / PowerCLI commands where useful). Keep the answer short.
TXT;

    private array $data;

    public function __construct()
    {
        $this->data = json_decode((string) Setting::get(self::KEY), true) ?: [];
    }

    /** @return list<array{key: string, label: string, url: string, mode: string, on: bool}> */
    public static function defaultProviders(): array
    {
        return [
            ['key' => 'claude', 'label' => 'Claude', 'url' => 'https://claude.ai/new?q={prompt}', 'mode' => 'prefill', 'on' => true],
            ['key' => 'chatgpt', 'label' => 'ChatGPT', 'url' => 'https://chatgpt.com/?q={prompt}', 'mode' => 'prefill', 'on' => true],
            ['key' => 'gemini', 'label' => 'Gemini', 'url' => 'https://gemini.google.com/app', 'mode' => 'copy', 'on' => true],
        ];
    }

    public function enabled(): bool
    {
        return (bool) ($this->data['on'] ?? true) && $this->activeProviders() !== [];
    }

    public function providers(): array
    {
        return $this->data['providers'] ?? self::defaultProviders();
    }

    public function activeProviders(): array
    {
        return array_values(array_filter($this->providers(), fn ($p) => $p['on'] ?? false));
    }

    public function anonymize(): bool
    {
        return (bool) ($this->data['anonymize'] ?? true);
    }

    public function language(): string
    {
        return array_key_exists($this->data['language'] ?? '', self::LANGUAGES) ? $this->data['language'] : 'system';
    }

    public function popup(): bool
    {
        return ($this->data['window'] ?? 'popup') === 'popup';
    }

    public function width(): int
    {
        return max(360, min(1200, (int) ($this->data['width'] ?? 520)));
    }

    public function maxFindings(): int
    {
        return max(1, min(50, (int) ($this->data['max_findings'] ?? 10)));
    }

    public function template(): string
    {
        return trim((string) ($this->data['prompt'] ?? '')) ?: self::DEFAULT_PROMPT;
    }

    public function raw(): array
    {
        return $this->data;
    }

    public function save(array $data): void
    {
        Setting::put(self::KEY, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->data = $data;
    }

    /** What the page's JavaScript needs (no prompts). */
    public function clientConfig(): array
    {
        return [
            'providers' => array_map(fn ($p) => ['label' => $p['label'], 'url' => $p['url'], 'mode' => $p['mode']], $this->activeProviders()),
            'popup' => $this->popup(), 'width' => $this->width(), 'language' => $this->language(),
        ];
    }

    /**
     * Prompt for one check. {language} is left for the browser to resolve ("system" = browser language).
     *
     * @param  Collection  $findings  CheckupFinding rows of this check, worst first
     */
    public function prompt(string $title, string $severity, int $affected, int $total, string $advice, Collection $findings, string $versions, ?Anonymizer $anon): string
    {
        $shown = $findings->take($this->maxFindings())->map(function ($f) use ($anon) {
            $name = $f->object_name ?? '—';
            $msg = $f->message;
            if ($anon) {
                $name = $anon->name($f->object_type, $name);
                $msg = $anon->text($msg);
            }

            return '- '.$name.' ('.$f->object_type.', '.$f->severity.'): '.$msg;
        });
        if ($findings->count() > $shown->count()) {
            $shown->push('- … '.($findings->count() - $shown->count()).' more similar findings');
        }

        $out = strtr($this->template(), [
            '{versions}' => $versions, '{title}' => $title, '{severity}' => $severity,
            '{affected}' => (string) $affected, '{total}' => (string) $total, '{advice}' => $advice,
            '{findings}' => $shown->join("\n"),
        ]);

        return str_contains($out, '{language}') ? $out : rtrim($out)."\n{language}";
    }

    /** "vCenter 7.0.3, ESXi 7.0.3" for the vCenters in scope */
    public static function versions(Collection $vcenters): string
    {
        $vc = $vcenters->map(fn ($v) => $v->about['version'] ?? null)->filter()->unique()->sort()->values();
        $esx = Inv\Host::whereIn('vcenter_id', $vcenters->modelKeys())->distinct()->pluck('version')->filter()->sort()->values();

        return collect([$vc->isNotEmpty() ? 'vCenter '.$vc->join(' / ') : null, $esx->isNotEmpty() ? 'ESXi '.$esx->join(' / ') : null])->filter()->join(', ') ?: 'vSphere';
    }

    /** Anonymizer knowing every inventory name of these vCenters */
    public static function anonymizer(Collection $vcenters): Anonymizer
    {
        $ids = $vcenters->modelKeys();
        $names = [];
        foreach ([
            'vcenter' => VCenter::whereKey($ids)->get()->flatMap(fn ($v) => [$v->name, $v->host, $v->ip ?? null]),
            'host' => Inv\Host::whereIn('vcenter_id', $ids)->pluck('name'),
            'cluster' => Inv\Cluster::whereIn('vcenter_id', $ids)->pluck('name'),
            'datastore' => Inv\Datastore::whereIn('vcenter_id', $ids)->pluck('name'),
            'network' => Inv\Network::whereIn('vcenter_id', $ids)->pluck('name'),
            'dvswitch' => Inv\DvSwitch::whereIn('vcenter_id', $ids)->pluck('name'),
            'vm' => Inv\Vm::whereIn('vcenter_id', $ids)->pluck('name'),
        ] as $type => $list) {
            foreach ($list->filter(fn ($n) => is_string($n) && mb_strlen($n) >= 2) as $n) {
                $names[$n] ??= $type;
            }
        }

        return new Anonymizer($names);
    }
}

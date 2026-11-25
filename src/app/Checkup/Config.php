<?php

namespace App\Checkup;

use App\Models\Setting;

/**
 * Checkup settings (Settings → Checkup), stored as JSON in app_settings:
 * {schedule: {mode: manual|schedule, hours}, checks: {key: {on, weight, p: {param: value}}}, exclude: {type: [patterns]}}
 */
final class Config
{
    private const KEY = 'checkup';

    public const HOURS = ['default' => 6, 'min' => 1, 'max' => 24];

    public const EXCLUDE_TYPES = ['vm', 'host', 'datastore', 'network'];

    private array $data;

    public function __construct()
    {
        $this->data = json_decode((string) Setting::get(self::KEY), true) ?: [];
    }

    public function scheduled(): bool
    {
        return ($this->data['schedule']['mode'] ?? 'schedule') === 'schedule';
    }

    public function hours(): int
    {
        return max(self::HOURS['min'], min(self::HOURS['max'], (int) ($this->data['schedule']['hours'] ?? self::HOURS['default'])));
    }

    public function enabled(Check $c): bool
    {
        return (bool) ($this->data['checks'][$c->key]['on'] ?? true);
    }

    public function weight(Check $c): int
    {
        return $c->severity === 'info' ? 0 : max(0, min(5, (int) ($this->data['checks'][$c->key]['weight'] ?? $c->weight)));
    }

    /** Parameter values (stored, or the defaults) */
    public function params(Check $c): array
    {
        $out = [];
        foreach ($c->params as $name => [$default]) {
            $v = $this->data['checks'][$c->key]['p'][$name] ?? $default;
            $out[$name] = is_int($default) ? (int) $v : (is_float($default) ? (float) $v : (string) $v);
        }

        return $out;
    }

    /** @return array<string, list<string>> */
    public function exclude(): array
    {
        $out = [];
        foreach (self::EXCLUDE_TYPES as $t) {
            $out[$t] = array_values(array_filter(array_map('trim', $this->data['exclude'][$t] ?? []), 'strlen'));
        }

        return $out;
    }

    public function save(array $data): void
    {
        Setting::put(self::KEY, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->data = $data;
    }

    public function raw(): array
    {
        return $this->data;
    }
}

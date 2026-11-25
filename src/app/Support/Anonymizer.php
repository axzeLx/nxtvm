<?php

namespace App\Support;

/**
 * Replaces inventory names and IPv4 addresses with neutral tokens (host-1, vm-2, ip-1 …) before a prompt leaves
 * the application. Tokens are numbered per prompt in order of appearance, so the same object keeps its token.
 * Free text such as snapshot or alarm names is not changed.
 */
class Anonymizer
{
    /** @var list<string> regexes over all known names, longest first (chunked to stay below PCRE limits) */
    private array $patterns = [];

    /** @var array<string, string> original → token */
    private array $map = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** @param  array<string, string>  $names  name → object type */
    public function __construct(private readonly array $names)
    {
        $list = array_keys($names);
        usort($list, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach (array_chunk($list, 400) as $chunk) {
            // alphanumeric boundaries: "BI" must not hit "BIOS", but "acm01" inside "_ovfenv-acm01.iso" is replaced
            $this->patterns[] = '/(?<![A-Za-z0-9])(?:'.implode('|', array_map(fn ($n) => preg_quote($n, '/'), $chunk)).')(?![A-Za-z0-9])/u';
        }
    }

    /** Same names, numbering starts again (one per prompt) */
    public function fresh(): self
    {
        $c = clone $this;
        $c->map = $c->counters = [];

        return $c;
    }

    public function name(string $type, string $name): string
    {
        return $this->token($name, $this->names[$name] ?? $type);
    }

    public function text(string $text): string
    {
        foreach ($this->patterns as $p) {
            $text = preg_replace_callback($p, fn ($m) => $this->token($m[0], $this->names[$m[0]] ?? 'object'), $text) ?? $text;
        }

        return preg_replace_callback('/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/', fn ($m) => $this->token($m[0], 'ip'), $text) ?? $text;
    }

    private function token(string $original, string $type): string
    {
        if (preg_match('/^(host|vm|datastore|network|cluster|dvswitch|vcenter|ip|object)-\d+$/', $original)) {
            return $original;   // already a token
        }

        return $this->map[$original] ??= $type.'-'.($this->counters[$type] = ($this->counters[$type] ?? 0) + 1);
    }
}

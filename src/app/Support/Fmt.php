<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Small display helpers used by the views. */
class Fmt
{
    public static function bytes(int|float|null $bytes, int $precision = 1): string
    {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        // fixed decimals from GB up (65.0 GB, not 65 GB) so values line up in right-aligned columns
        return number_format($bytes, $i < 3 ? 0 : $precision, '.', '').' '.$units[$i];
    }

    /** Short guest OS name for titles: "Microsoft Windows Server 2016 (64-bit)" → "Windows 2016". */
    public static function osShort(?string $os): string
    {
        if (! $os) {
            return '';
        }
        $s = preg_replace(['/^Microsoft\s+/i', '/\bServer\s+/i', '/\s*\((32|64)-bit\)/i'], '', $os);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    public static function kb(int|float|null $kb): string
    {
        return $kb === null ? '—' : self::bytes($kb * 1024);
    }

    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $d ? "{$d}d {$h}h" : ($h ? "{$h}h {$m}m" : "{$m}m");
    }

    public static function interval(int $seconds): string
    {
        return $seconds % 3600 === 0 ? ($seconds / 3600).' h' : ($seconds % 60 === 0 ? ($seconds / 60).' min' : $seconds.' s');
    }

    public static function ago(?CarbonInterface $at): string
    {
        return $at ? $at->diffForHumans(short: true) : __('never');
    }

    public static function time(?CarbonInterface $at): string
    {
        return $at ? $at->timezone(config('app.display_timezone'))->format('Y-m-d H:i') : '—';
    }

    /** "Intel(R) Xeon(R) Platinum 8380 CPU @ 2.30GHz" → "Intel Xeon Platinum 8380" */
    public static function cpuShort(?string $model): string
    {
        if (! $model) {
            return '—';
        }
        $m = preg_replace(['/\((R|TM|tm)\)/', '/\s+CPU\b/', '/\s*@.*$/', '/\s+\d+-Core Processor/i', '/\s+Processor$/i'], '', $model);

        return trim(preg_replace('/\s+/', ' ', $m));
    }

    /** Usage percentage → level for colouring. */
    public static function pctLevel(?float $pct, float $warn = 80, float $crit = 90): string
    {
        return match (true) {
            $pct === null => 'unknown',
            $pct >= $crit => 'crit',
            $pct >= $warn => 'warn',
            default => 'ok',
        };
    }

    public static function mb(int|float|null $mb): string
    {
        return $mb === null ? '—' : self::bytes($mb * 1048576);
    }

    public static function pct(int|float|null $part, int|float|null $total, int $precision = 0): ?float
    {
        return ($total ?? 0) > 0 && $part !== null ? round($part / $total * 100, $precision) : null;
    }

    public static function date(?CarbonInterface $at): string
    {
        return $at ? $at->timezone(config('app.display_timezone'))->format('Y-m-d') : '—';
    }

    /** vSphere enum strings → short labels: poweredOn → on, guestToolsRunning → running */
    public static function state(?string $s): string
    {
        return match ($s) {
            null, '' => '—',
            'poweredOn' => 'on', 'poweredOff' => 'off', 'suspended' => 'suspended',
            'guestToolsRunning' => 'running', 'guestToolsNotRunning' => 'not running', 'guestToolsExecutingScripts' => 'starting',
            'guestToolsCurrent' => 'current', 'guestToolsNeedUpgrade' => 'upgrade', 'guestToolsNotInstalled' => 'not installed',
            'guestToolsUnmanaged' => 'unmanaged', 'guestToolsSupportedOld' => 'old', 'guestToolsSupportedNew' => 'newer',
            'guestToolsTooOld' => 'too old', 'guestToolsTooNew' => 'too new', 'guestToolsBlacklisted' => 'blacklisted',
            default => $s,
        };
    }

    /** vSphere colour status → level */
    public static function vsLevel(?string $status): string
    {
        return match ($status) {
            'green' => 'ok', 'yellow' => 'warn', 'red' => 'crit', default => 'unknown',
        };
    }
}

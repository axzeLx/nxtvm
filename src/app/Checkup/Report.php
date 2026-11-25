<?php

namespace App\Checkup;

use App\Models\CheckupFinding;
use App\Models\CheckupRun;
use Illuminate\Support\Collection;

/** Latest checkup results of one or more vCenters, combined for the Checkup page. */
final class Report
{
    /** @var Collection<int, CheckupRun> keyed by vCenter id */
    public Collection $runs;

    /** @var Collection<string, Collection> findings by check key, worst first */
    public Collection $findings;

    /** @var array<string, array> check key → combined result */
    public array $checks = [];

    public function __construct(Collection $vcenterIds, public readonly Registry $registry)
    {
        $this->runs = CheckupRun::whereIn('vcenter_id', $vcenterIds)->get()->keyBy('vcenter_id');
        $this->findings = CheckupFinding::whereIn('vcenter_id', $this->runs->keys())->get()
            ->sortBy(fn ($f) => [CheckupFinding::RANK[$f->severity] ?? 9, $f->vcenter_id, mb_strtolower((string) $f->object_name)])
            ->groupBy('check');

        foreach ($registry->all() as $key => $check) {
            $parts = $this->runs->map(fn ($r) => $r->results[$key] ?? null)->filter();
            if ($parts->isEmpty()) {
                continue;    // disabled (or added after the last run)
            }
            $c = ['category' => $check->category, 'total' => $parts->sum('total'), 'crit' => $parts->sum('crit'),
                'warn' => $parts->sum('warn'), 'info' => $parts->sum('info'), 'weight' => $parts->max('weight'),
                'na' => $parts->pluck('na')->filter()->first(), 'error' => $parts->pluck('error')->filter()->first()];
            $applicable = $parts->reject(fn ($p) => in_array($p['status'], ['na', 'error'], true));
            $c['status'] = match (true) {
                $c['crit'] > 0 => 'crit', $c['warn'] > 0 => 'warn', $c['info'] > 0 => 'info',
                $applicable->isNotEmpty() => 'ok',
                $c['error'] !== null => 'error',
                default => 'na',
            };
            $c['score'] = Score::check($c);
            $this->checks[$key] = $c;
        }
    }

    public function empty(): bool
    {
        return $this->runs->isEmpty();
    }

    /** Overall score: every vCenter's checks weighted together */
    public function score(?string $category = null): ?int
    {
        return Score::of($this->runs->flatMap(fn ($r) => array_values($r->results)), $category);
    }

    /** Checks of a category that found something, worst first */
    public function problems(?string $category = null): Collection
    {
        return collect($this->checks)
            ->filter(fn ($c) => in_array($c['status'], ['crit', 'warn', 'info'], true) && ($category === null || $c['category'] === $category))
            ->sortBy(fn ($c, $k) => [['crit' => 0, 'warn' => 1, 'info' => 2][$c['status']], -($c['weight'] * (1 - ($c['score'] ?? 1))), -($c['crit'] + $c['warn'] + $c['info'])]);
    }

    public function passed(string $category): Collection
    {
        return collect($this->checks)->filter(fn ($c) => $c['category'] === $category && $c['status'] === 'ok');
    }

    public function notApplicable(string $category): Collection
    {
        return collect($this->checks)->filter(fn ($c) => $c['category'] === $category && in_array($c['status'], ['na', 'error'], true));
    }

    /** Finding counts by severity for a category (or all) */
    public function counts(?string $category = null): array
    {
        $cs = collect($this->checks)->filter(fn ($c) => $category === null || $c['category'] === $category);

        return ['crit' => $cs->sum('crit'), 'warn' => $cs->sum('warn'), 'info' => $cs->sum('info')];
    }
}

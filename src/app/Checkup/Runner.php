<?php

namespace App\Checkup;

use App\Models\CheckupFinding;
use App\Models\CheckupRun;
use App\Models\VCenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs all enabled checks against the collected inventory of a vCenter and stores the latest result. */
final class Runner
{
    public function __construct(private readonly Registry $registry, private readonly Config $config) {}

    public function run(VCenter $vc, string $trigger = 'manual'): ?CheckupRun
    {
        if (! $vc->last_success_at) {
            return null;    // nothing collected yet
        }
        $started = microtime(true);
        $ctx = new Context($vc, $this->config->exclude());
        $results = $findings = [];

        foreach ($this->registry->all() as $key => $check) {
            if (! $this->config->enabled($check)) {
                continue;
            }
            $res = new Result($check->severity, $vc->name);
            $error = null;
            try {
                ($check->run)($ctx, $this->config->params($check), $res);
            } catch (Throwable $e) {
                Log::warning('Checkup check failed', ['check' => $key, 'vcenter' => $vc->name, 'exception' => $e]);
                $error = mb_substr(class_basename($e).': '.$e->getMessage(), 0, 300);
            }

            if ($error === null && $res->total === 0 && ! $res->findings && $res->na === null) {
                $res->na(__('Nothing to check.'));
            }
            $crit = $res->count('crit');
            $warn = $res->count('warn');
            $info = $res->count('info');
            $status = match (true) {
                $error !== null => 'error',
                $res->na !== null && ! $res->findings => 'na',
                $crit > 0 => 'crit', $warn > 0 => 'warn', $info > 0 => 'info',
                default => 'ok',
            };
            $results[$key] = [
                'category' => $check->category, 'status' => $status, 'total' => $res->total,
                'crit' => $crit, 'warn' => $warn, 'info' => $info,
                'weight' => $this->config->weight($check), 'na' => $res->na, 'error' => $error,
                'note' => $error === null ? $res->note : null,
            ];
            $results[$key]['score'] = Score::check($results[$key]);

            foreach ($error === null ? $res->findings : [] as $f) {
                $findings[] = $f + ['vcenter_id' => $vc->id, 'check' => $key, 'category' => $check->category];
            }
        }

        return DB::transaction(function () use ($vc, $trigger, $results, $findings, $started) {
            CheckupFinding::where('vcenter_id', $vc->id)->delete();
            foreach (array_chunk($findings, 500) as $chunk) {
                CheckupFinding::insert($chunk);
            }

            return CheckupRun::updateOrCreate(['vcenter_id' => $vc->id], [
                'ran_at' => now(), 'trigger' => $trigger, 'score' => Score::of($results),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'inventory_at' => $vc->last_success_at, 'results' => $results,
            ]);
        });
    }

    /** vCenters whose scheduled checkup is due */
    public function due(): \Illuminate\Support\Collection
    {
        if (! $this->config->scheduled()) {
            return collect();
        }
        $last = CheckupRun::pluck('ran_at', 'vcenter_id');

        return VCenter::ordered()->whereNotNull('last_success_at')->get()
            ->filter(fn ($vc) => ! isset($last[$vc->id]) || $last[$vc->id]->lte(now()->subHours($this->config->hours())->addMinute()));
    }
}

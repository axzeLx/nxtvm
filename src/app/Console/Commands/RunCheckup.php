<?php

namespace App\Console\Commands;

use App\Checkup\Runner;
use App\Models\VCenter;
use App\Support\Modules;
use Illuminate\Console\Command;

class RunCheckup extends Command
{
    protected $signature = 'nxtvm:checkup {vcenter? : vCenter id (default: all)} {--due : Only vCenters whose scheduled checkup is due}';

    protected $description = 'Run the Checkup (licensed module) on the collected inventory';

    public function handle(Runner $runner, Modules $modules): int
    {
        if (! $modules->enabled(Modules::CHECKUP)) {
            if (! $this->option('due')) {
                $this->error('The Checkup module is not licensed.');
            }

            return $this->option('due') ? self::SUCCESS : self::FAILURE;
        }

        $vcs = $this->option('due') ? $runner->due()
            : VCenter::ordered()->when($this->argument('vcenter'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($vcs as $vc) {
            $run = $runner->run($vc, $this->option('due') ? 'schedule' : 'manual');
            $this->line(sprintf('%-30s %s', $vc->name, $run ? "score {$run->score} ({$run->duration_ms} ms)" : 'skipped (no inventory)'));
        }

        return self::SUCCESS;
    }
}

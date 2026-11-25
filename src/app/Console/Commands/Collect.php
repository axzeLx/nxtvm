<?php

namespace App\Console\Commands;

use App\Jobs\CollectVCenter;
use App\Models\VCenter;
use Illuminate\Console\Command;

class Collect extends Command
{
    protected $signature = 'nxtvm:collect {vcenter? : Name or id (default: all enabled)} {--queue : Dispatch to the worker instead of running here}';

    protected $description = 'Collect the inventory of one or all vCenters now';

    public function handle(): int
    {
        $arg = $this->argument('vcenter');
        $vcenters = $arg
            ? VCenter::where('name', $arg)->orWhere('id', ctype_digit($arg) ? (int) $arg : 0)->get()
            : VCenter::where('is_enabled', true)->get();

        if ($vcenters->isEmpty()) {
            $this->error('No matching vCenter.');

            return self::FAILURE;
        }

        foreach ($vcenters as $vc) {
            if ($this->option('queue')) {
                CollectVCenter::dispatch($vc->id);
                $this->info("{$vc->name}: queued");

                continue;
            }
            $t = microtime(true);
            $this->line("{$vc->name}: collecting…");
            CollectVCenter::dispatchSync($vc->id);
            $vc->refresh();
            $this->line(sprintf('%s: %s in %.1f s', $vc->name, $vc->last_error ? '<error>failed</error> '.$vc->last_error : '<info>ok</info>', microtime(true) - $t));
        }

        return self::SUCCESS;
    }
}

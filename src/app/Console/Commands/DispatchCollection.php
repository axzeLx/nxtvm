<?php

namespace App\Console\Commands;

use App\Models\VCenter;
use Illuminate\Console\Command;

class DispatchCollection extends Command
{
    protected $signature = 'nxtvm:dispatch';

    protected $description = 'Queue a collection for every enabled vCenter whose interval has elapsed';

    public function handle(): int
    {
        foreach (VCenter::where('is_enabled', true)->get() as $vc) {
            if (! $vc->last_collect_at || $vc->last_collect_at->lte(now()->subMinutes($vc->interval_minutes))) {
                $vc->queueCollect();
            }
        }

        return self::SUCCESS;
    }
}

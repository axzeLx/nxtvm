<?php

use Illuminate\Support\Facades\Schedule;

// Per-vCenter intervals are evaluated every minute; due vCenters get one collection job each.
Schedule::command('nxtvm:dispatch')->everyMinute()->withoutOverlapping();

// Retention of the audit log
Schedule::command('model:prune', ['--model' => [App\Models\AuditLog::class]])->dailyAt('03:15');

// Scheduled Checkup (licensed module): runs on vCenters whose interval (Settings → Checkup) has elapsed
Schedule::command('nxtvm:checkup', ['--due'])->everyMinute()->withoutOverlapping();

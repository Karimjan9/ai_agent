<?php

namespace App\Console\Commands;

use App\Services\LabLifecycleWatchdogService;
use Illuminate\Console\Command;

class WatchLabLifecycle extends Command
{
    protected $signature = 'trading:watch-lab-lifecycle {--repair : Safely requeue only abandoned full-validation jobs (maximum two retries)}';

    protected $description = 'Audit laboratory lifecycle and evidence ledgers without relaxing promotion gates';

    public function handle(LabLifecycleWatchdogService $watchdog): int
    {
        $events = $watchdog->inspect((bool) $this->option('repair'));
        $new = collect($events)->where('new', true)->count();
        $this->info("Lab lifecycle watchdog: ".count($events)." findings, {$new} newly logged.");

        return self::SUCCESS;
    }
}

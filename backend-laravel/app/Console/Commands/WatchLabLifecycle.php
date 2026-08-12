<?php

namespace App\Console\Commands;

use App\Services\LabLifecycleWatchdogService;
use App\Services\LabQueueJobInspector;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;
use RuntimeException;

class WatchLabLifecycle extends Command
{
    protected $signature = 'trading:watch-lab-lifecycle {--repair : Apply bounded lifecycle repair after explicit approval} {--approved-by=} {--approval-reason=}';

    protected $description = 'Audit laboratory lifecycle and evidence ledgers without relaxing promotion gates';

    public function handle(LabLifecycleWatchdogService $watchdog, LabQueueJobInspector $queue, OperatorApprovalService $approvals): int
    {
        $repair = (bool) $this->option('repair');
        if (! $repair) {
            $events = $watchdog->inspect(false);
            $new = collect($events)->where('new', true)->count();
            $this->info("Lab lifecycle watchdog dry-run: ".count($events)." findings, {$new} newly logged; no repair applied.");

            return self::SUCCESS;
        }
        $backlog = $queue->labQueueBacklog();
        if ($backlog['total'] > 0) {
            $this->warn('Lifecycle repair deferred: '.$backlog['total'].' lab queue job(s) remain.');

            return self::SUCCESS;
        }
        try {
            $approvals->requireForApply('watch-lab-lifecycle', $this->option('approved-by'), $this->option('approval-reason'), [
                'queue_backlog' => $backlog,
            ]);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $events = $watchdog->inspect(true);
        $new = collect($events)->where('new', true)->count();
        $this->info("Lab lifecycle watchdog: ".count($events)." findings, {$new} newly logged.");

        return self::SUCCESS;
    }
}

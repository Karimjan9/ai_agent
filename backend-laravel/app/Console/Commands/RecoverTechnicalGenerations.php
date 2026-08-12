<?php

namespace App\Console\Commands;

use App\Services\TechnicalGenerationRecoveryService;
use App\Services\LabQueueJobInspector;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;
use RuntimeException;

class RecoverTechnicalGenerations extends Command
{
    protected $signature = 'trading:recover-technical-generations {--generation=25,29} {--older-than=90} {--apply : Dispatch the single retry or technical quarantine} {--approved-by=} {--approval-reason=}';
    protected $description = 'Append-only recovery for stale G25/G29 screening work; never creates a quality verdict';

    public function handle(TechnicalGenerationRecoveryService $recovery, LabQueueJobInspector $queue, OperatorApprovalService $approvals): int
    {
        $numbers = collect(explode(',', (string) $this->option('generation')))->map(fn ($value) => (int) trim($value))->filter()->values()->all();
        $apply = (bool) $this->option('apply');
        if ($apply) {
            $backlog = $queue->labQueueBacklog();
            if ($backlog['total'] > 0) {
                $this->warn(sprintf(
                    'Technical recovery apply deferred: %d lab job(s) remain in %s.',
                    $backlog['total'],
                    implode(', ', array_keys($backlog['queues'])),
                ));

                return self::SUCCESS;
            }
            try {
                $approvals->requireForApply('recover-technical-generations', $this->option('approved-by'), $this->option('approval-reason'), [
                    'generation' => $numbers ?: [25, 29],
                    'older_than_minutes' => (int) $this->option('older-than'),
                ]);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }
        $result = $recovery->recover($numbers ?: [25, 29], (int) $this->option('older-than'), $apply);
        $this->table(['retry', 'technical_quarantine', 'skipped', 'reason'], [[
            $result['retried'], $result['quarantined'], $result['skipped'], $result['reason'],
        ]]);
        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\IncompleteLabEvidenceRecoveryService;
use App\Services\LabQueueJobInspector;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecoverIncompleteLabEvidence extends Command
{
    protected $signature = 'trading:recover-incomplete-lab-evidence {symbol?} {--timeframe=} {--generation=} {--limit=20} {--apply : Dispatch one bounded same-generation replay per agent} {--approved-by=} {--approval-reason=} {--scheduled-sweep : Restrict automatic recovery to recent evidence-pipeline cohorts} {--json}';
    protected $description = 'Recover incomplete screening evidence without converting it into a strategy failure';

    public function handle(IncompleteLabEvidenceRecoveryService $recovery, OperatorApprovalService $approvals, LabQueueJobInspector $queue): int
    {
        $apply = (bool) $this->option('apply');
        $scheduledSweep = (bool) $this->option('scheduled-sweep');
        $approval = null;
        $lock = $apply ? Cache::lock('trading:recover-incomplete-lab-evidence:v1', 300) : null;
        if ($lock !== null && ! $lock->get()) {
            $this->info('Incomplete-evidence recovery already active; this invocation was safely deferred.');

            return self::SUCCESS;
        }

        try {
            $queueBacklog = $queue->labQueueBacklog();
            if ($apply && ($queueBacklog['total'] === null || $queueBacklog['total'] > 0)) {
                $result = [
                    'selected' => 0,
                    'requeued' => 0,
                    'quarantined' => 0,
                    'skipped' => 0,
                    'deferred' => true,
                    'action' => 'deferred_queue_backlog',
                    'queue_backlog' => $queueBacklog,
                    'rows' => [],
                ];
                if ($this->option('json')) {
                    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    $this->warn(sprintf(
                        'Recovery queue deferred: %d existing job(s) remain in %s.',
                        $queueBacklog['total'],
                        implode(', ', array_keys($queueBacklog['queues'])),
                    ));
                }

                return self::SUCCESS;
            }

            if ($apply) {
                try {
                    $approval = $approvals->requireForApply('recover-incomplete-lab-evidence', $this->option('approved-by'), $this->option('approval-reason'), [
                        'symbol' => $this->argument('symbol'), 'timeframe' => $this->option('timeframe'), 'generation' => $this->option('generation'),
                    ]);
                } catch (RuntimeException $exception) {
                    $this->error($exception->getMessage());
                    return self::FAILURE;
                }
            }

            $result = $recovery->recover(
                $this->argument('symbol') ? strtoupper((string) $this->argument('symbol')) : null,
                $this->option('timeframe') ? strtoupper((string) $this->option('timeframe')) : null,
                $this->option('generation') !== null ? (int) $this->option('generation') : null,
                (int) $this->option('limit'),
                $apply,
                $scheduledSweep,
                $approval,
            );
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->table(['selected', 'requeued', 'technical_quarantine', 'skipped'], [[
                    $result['selected'], $result['requeued'], $result['quarantined'], $result['skipped'],
                ]]);
                foreach ($result['rows'] as $row) {
                    $this->line(sprintf(
                        'A%s G%s %s: %s',
                        $row['agent_id'], $row['generation_id'], $row['action'], $row['reason'],
                    ));
                }
            }

            return self::SUCCESS;
        } finally {
            optional($lock)->release();
        }
    }

}

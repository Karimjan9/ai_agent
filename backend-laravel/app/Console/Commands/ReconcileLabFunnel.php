<?php

namespace App\Console\Commands;

use App\Services\LabFunnelReconciliationService;
use App\Services\LabQueueJobInspector;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;
use RuntimeException;

class ReconcileLabFunnel extends Command
{
    protected $signature = 'trading:reconcile-lab-funnel {symbol?} {--timeframe=} {--apply : Persist only proven stale full-validation projections} {--approved-by=} {--approval-reason=} {--json}';
    protected $description = 'Reconcile stale full-validation lifecycle projections without changing gates';

    public function handle(LabFunnelReconciliationService $reconciler, OperatorApprovalService $approvals, LabQueueJobInspector $queue): int
    {
        $approval = null;
        if ($this->option('apply')) {
            $backlog = $queue->labQueueBacklog();
            if ($backlog['total'] > 0) {
                $result = [
                    'inspected' => 0,
                    'reconciled' => 0,
                    'skipped' => 0,
                    'deferred' => true,
                    'action' => 'deferred_queue_backlog',
                    'queue_backlog' => $backlog,
                    'rows' => [],
                ];
                if ($this->option('json')) {
                    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    $this->warn(sprintf(
                        'Reconciliation apply deferred: %d lab job(s) remain in %s.',
                        $backlog['total'],
                        implode(', ', array_keys($backlog['queues'])),
                    ));
                }

                return self::SUCCESS;
            }
            try {
                $approval = $approvals->requireForApply('reconcile-lab-funnel', $this->option('approved-by'), $this->option('approval-reason'), [
                    'symbol' => $this->argument('symbol'), 'timeframe' => $this->option('timeframe'),
                ]);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());
                return self::FAILURE;
            }
        }
        $result = $reconciler->reconcile(
            $this->argument('symbol') ? strtoupper((string) $this->argument('symbol')) : null,
            $this->option('timeframe') ? strtoupper((string) $this->option('timeframe')) : null,
            (bool) $this->option('apply'),
            $approval,
        );
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['inspected', 'reconciled', 'skipped'], [[
                $result['inspected'], $result['reconciled'], $result['skipped'],
            ]]);
            foreach ($result['rows'] as $row) {
                $this->line(sprintf(
                    '%s %s G%s: %s (%s)',
                    $row['symbol'], $row['timeframe'], $row['generation'], $row['action'],
                    $row['eligible_for_projection_repair'] ? 'no eligible full candidate' : 'held',
                ));
            }
        }

        return self::SUCCESS;
    }
}

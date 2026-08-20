<?php

namespace App\Console\Commands;

use App\Services\LearningRecoveryReconciliationService;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;

class ReconcileLearningRecovery extends Command
{
    protected $signature = 'trading:reconcile-learning-recovery {symbol?} {--timeframe=H1} {--limit=100} {--apply} {--approved-by=} {--approval-reason=} {--json}';

    protected $description = 'Reconcile pending Dojo and failed lab jobs through append-only recovery projections';

    public function handle(LearningRecoveryReconciliationService $recovery, OperatorApprovalService $approvals): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $apply = (bool) $this->option('apply');
        if ($apply) {
            $approvals->requireForApply('reconcile-learning-recovery', $this->option('approved-by'), $this->option('approval-reason'), [
                'symbol' => $symbol, 'timeframe' => $timeframe, 'limit' => (int) $this->option('limit'),
            ]);
        }
        $result = $recovery->reconcile($symbol, $timeframe, max(1, (int) $this->option('limit')), $apply);
        if ($this->option('json')) $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        else $this->info(sprintf('Learning recovery %s: dojo queued=%d, diagnostic=%d, failed jobs requeued=%d.', $apply ? 'applied' : 'preview', $result['dojo_recovery_queued'] ?? 0, $result['dojo_diagnostic_only'] ?? 0, $result['failed_jobs_requeued'] ?? 0));
        return self::SUCCESS;
    }
}

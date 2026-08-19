<?php

namespace App\Console\Commands;

use App\Services\OperatorApprovalService;
use App\Services\ParentCandidatePreparationService;
use Illuminate\Console\Command;

class PrepareParentCandidates extends Command
{
    protected $signature = 'trading:prepare-parent-candidates {symbol?} {--timeframe=H1} {--limit=20} {--apply} {--approved-by=} {--approval-reason=} {--json}';

    protected $description = 'Prepare bounded parent ideas only for council agents that already meet the parent pre-pass';

    public function handle(ParentCandidatePreparationService $preparation, OperatorApprovalService $approvals): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $apply = (bool) $this->option('apply');
        if ($apply) {
            $approvals->requireForApply('prepare-parent-candidates', $this->option('approved-by'), $this->option('approval-reason'), [
                'symbol' => $symbol, 'timeframe' => $timeframe, 'limit' => (int) $this->option('limit'),
            ]);
        }
        $result = $preparation->prepare($symbol, $timeframe, (int) $this->option('limit'), $apply);
        if ($this->option('json')) $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        else $this->info(sprintf('%s: candidates=%d, ideas=%d; parent gate bypass=false.', $apply ? 'applied' : 'dry_run', $result['candidate_count'] ?? 0, $result['ideas'] ?? 0));

        return self::SUCCESS;
    }
}

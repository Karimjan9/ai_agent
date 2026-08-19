<?php

namespace App\Console\Commands;

use App\Services\CausalControlCohortPlanner;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;

class PlanCausalControlCohort extends Command
{
    protected $signature = 'trading:plan-causal-control-cohort {symbol?} {--timeframe=H1} {--family=} {--limit=50} {--apply} {--approved-by=} {--approval-reason=} {--json}';

    protected $description = 'Plan exact hash-matched causal controls without fabricating learning pairs or dispatching replay';

    public function handle(CausalControlCohortPlanner $planner, OperatorApprovalService $approvals): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $family = (string) $this->option('family') ?: null;
        $apply = (bool) $this->option('apply');
        if ($apply) {
            $approvals->requireForApply('plan-causal-control-cohort', $this->option('approved-by'), $this->option('approval-reason'), [
                'symbol' => $symbol, 'timeframe' => $timeframe, 'family' => $family, 'limit' => (int) $this->option('limit'),
            ]);
        }
        $result = $planner->plan($symbol, $timeframe, $family, (int) $this->option('limit'), $apply);
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                '%s: inspected=%d, planned=%d, blocked=%d; replay dispatch was not performed.',
                $apply ? 'applied' : 'dry_run',
                $result['inspected'] ?? 0,
                $result['planned'] ?? 0,
                $result['blocked'] ?? 0,
            ));
        }

        return self::SUCCESS;
    }
}

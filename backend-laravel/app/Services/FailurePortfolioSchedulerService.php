<?php

namespace App\Services;

use App\Models\LabFailureDojoRun;

/** Deduplicated information-gain scheduler with explicit Hyperband fold budgets. */
class FailurePortfolioSchedulerService
{
    public const PROTOCOL = 'failure_portfolio_scheduler_v1';

    /** @param iterable<LabFailureDojoRun> $runs @return array<int,LabFailureDojoRun> */
    public function schedule(iterable $runs, int $limit): array
    {
        return collect($runs)->groupBy(fn (LabFailureDojoRun $run): string => (string) ($run->state_signature ?: $run->id))
            ->map(fn ($cluster) => $cluster->sortByDesc(fn (LabFailureDojoRun $run): float => $this->priority($run))->first())
            ->sortByDesc(fn (LabFailureDojoRun $run): float => $this->priority($run))->take(max(1, $limit))->values()->each(function (LabFailureDojoRun $run): void {
                $evidence = (array) $run->evidence;
                $evidence['failure_portfolio_scheduler'] = ['protocol' => self::PROTOCOL, 'priority' => $this->priority($run),
                    'fold_allocation' => $this->foldAllocation($run), 'terminal_states' => ['repaired', 'contradicted', 'retired_no_edge', 'structural_escape_created', 'irrecoverable_technical'], 'promotion_evidence' => false];
                $run->update(['evidence' => $evidence]);
            })->all();
    }

    private function priority(LabFailureDojoRun $run): float
    {
        $evidence = (array) $run->evidence;
        $repair = max(.01, (float) data_get($evidence, 'strategic_research_director.experiment_value.repair_probability', .5));
        $gain = max(.01, (float) data_get($evidence, 'information_gain_priority.score', .1));
        $leverage = max(.01, (float) data_get($evidence, 'information_gain_priority.components.causal_leverage', .1));
        $recurrence = 1 + max(0, (int) data_get($run->failure_signature, 'repeat_count', 0));
        $cost = max(.1, (float) data_get($evidence, 'estimated_compute_cost', 1));
        return round(($repair * $gain * $leverage * $recurrence) / $cost, 6);
    }

    private function foldAllocation(LabFailureDojoRun $run): array
    {
        $priority = $this->priority($run);
        return ['protocol' => 'hyperband_authority_allocation_v1', 'initial_folds' => 2,
            'interim_folds' => $priority >= .15 ? 3 : 0, 'final_folds' => $priority >= .30 ? 9 : 0,
            'early_stop' => 'clear_target_failure_or_non_target_regression'];
    }
}

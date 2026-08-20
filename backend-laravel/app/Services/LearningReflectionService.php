<?php

namespace App\Services;

/** Turns a settled failure into a bounded, executable next experiment. */
class LearningReflectionService
{
    /** @return array<string, mixed> */
    public function reflect(array $outcome, array $reward): array
    {
        $metrics = (array) ($outcome['metrics'] ?? $outcome);
        $failure = $this->failureClass($outcome, $reward);
        $target = match ($failure) {
            'drawdown_risk', 'risk_of_ruin' => 'drawdown_risk',
            'temporal_instability' => 'temporal_stability',
            'execution_failure', 'data_failure' => 'technical_repair',
            'abstention_failure' => 'abstention_quality',
            default => 'edge_quality',
        };
        return [
            'failure' => $failure,
            'lesson' => 'Test one bounded repair for '.$failure.'; do not change live policy.',
            'next_action' => $failure === 'execution_failure' || $failure === 'data_failure'
                ? 'repair_execution_or_data_contract'
                : 'mutate_'.$target,
            'test' => 'one_gene_paired_replay',
            'success_condition' => $target === 'drawdown_risk'
                ? 'drawdown improves without profit-factor collapse'
                : 'target improves without non-target regression',
            'metrics_seen' => $metrics,
        ];
    }

    public function failureClass(array $outcome, array $reward): string
    {
        $metrics = (array) ($outcome['metrics'] ?? $outcome);
        if (($metrics['data_drift'] ?? false) === true) return 'data_failure';
        if (($metrics['execution_drift'] ?? false) === true) return 'execution_failure';
        if (in_array('DRAWDOWN_LIMIT', (array) ($reward['vetoes'] ?? []), true)) return 'drawdown_risk';
        if (in_array('RISK_OF_RUIN_LIMIT', (array) ($reward['vetoes'] ?? []), true)) return 'risk_of_ruin';
        if (in_array('STRESS_PF_LIMIT', (array) ($reward['vetoes'] ?? []), true) || ($metrics['temporal_firewall_passed'] ?? true) !== true) return 'temporal_instability';
        $declared = (string) ($outcome['failure_class'] ?? '');
        if ($declared !== '') return $declared;
        if (($metrics['abstention_quality'] ?? 1) < .5) return 'abstention_failure';
        return (($reward['selection_reward'] ?? 0) < 0) ? 'entry_quality' : 'uncertain';
    }
}

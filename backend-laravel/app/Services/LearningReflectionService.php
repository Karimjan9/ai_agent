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
            'analysis_error' => 'regime_coverage',
            'selection_error' => 'selection_quality',
            'execution_error' => 'execution_quality',
            'risk_error' => 'drawdown_risk',
            'management_error' => 'management_quality',
            'process_integrity_error' => 'technical_repair',
            'adaptation_error', 'evidence_completion' => 'evidence_completion',
            default => 'edge_quality',
        };

        return [
            'failure' => $failure,
            'lesson' => 'Test one bounded repair for '.$failure.'; do not change live policy.',
            'next_action' => in_array($failure, ['execution_failure', 'data_failure', 'process_integrity_error'], true)
                ? 'repair_execution_or_data_contract'
                : ($target === 'evidence_completion' ? 'collect_missing_independent_evidence' : 'mutate_'.$target),
            'test' => $target === 'evidence_completion' ? 'bounded_evidence_completion' : 'one_gene_paired_replay',
            'success_condition' => $target === 'drawdown_risk'
                ? 'drawdown improves without profit-factor collapse'
                : 'target improves without non-target regression',
            'metrics_seen' => $metrics,
        ];
    }

    public function failureClass(array $outcome, array $reward): string
    {
        $metrics = (array) ($outcome['metrics'] ?? $outcome);
        if (($metrics['data_drift'] ?? false) === true) {
            return 'data_failure';
        }
        if (($metrics['execution_drift'] ?? false) === true) {
            return 'execution_failure';
        }
        if (in_array('DRAWDOWN_LIMIT', (array) ($reward['vetoes'] ?? []), true)) {
            return 'drawdown_risk';
        }
        if (in_array('RISK_OF_RUIN_LIMIT', (array) ($reward['vetoes'] ?? []), true)) {
            return 'risk_of_ruin';
        }
        if (in_array('STRESS_PF_LIMIT', (array) ($reward['vetoes'] ?? []), true) || ($metrics['temporal_firewall_passed'] ?? true) !== true) {
            return 'temporal_instability';
        }
        $processQuadrant = (string) data_get($metrics, 'process_outcome_audit.quadrant', 'UNRESOLVED');
        if (in_array($processQuadrant, ['BAD_WIN', 'BAD_LOSS', 'BAD_BREAKEVEN'], true)
            || in_array('BAD_PROCESS_OUTCOME', (array) ($reward['vetoes'] ?? []), true)) {
            return 'process_integrity_error';
        }
        if ($processQuadrant === 'GOOD_LOSS') {
            return 'good_process_negative_outcome';
        }
        $traderFitness = (array) data_get($metrics, 'evolving_trader_fitness', []);
        $fitnessPractice = (array) data_get($traderFitness, 'next_deliberate_practice', []);
        if ((string) data_get($traderFitness, 'status') === 'complete_powered'
            && (string) ($fitnessPractice['action'] ?? '') === 'one_axis_paired_bottleneck_repair'
            && ($fitnessPractice['rule_change_authorized'] ?? false) === true) {
            return $this->fitnessFailureClass((string) ($fitnessPractice['target'] ?? ''));
        }

        $scorecardDirective = (array) data_get($metrics, 'trading_operating_system_scorecard.evolution_directive', []);
        if (($scorecardDirective['status'] ?? null) === 'measured_bottleneck') {
            return match ((string) ($scorecardDirective['target'] ?? '')) {
                'edge_quality' => 'strategy_error',
                'selection_quality' => 'selection_error',
                'execution_quality' => 'execution_error',
                'drawdown_risk' => 'risk_error',
                'management_quality' => 'management_error',
                'technical_repair' => 'process_integrity_error',
                default => 'adaptation_error',
            };
        }
        $declared = (string) ($outcome['failure_class'] ?? '');
        if ($declared !== '') {
            return $declared;
        }
        if (($metrics['abstention_quality'] ?? 1) < .5) {
            return 'abstention_failure';
        }

        return (($reward['selection_reward'] ?? 0) < 0) ? 'entry_quality' : 'uncertain';
    }

    private function fitnessFailureClass(string $dimension): string
    {
        return match ($dimension) {
            'market_understanding', 'regime_recognition', 'context_bias' => 'analysis_error',
            'edge_quality' => 'strategy_error',
            'setup_selection', 'confirmation_quality' => 'selection_error',
            'entry_precision', 'execution_quality' => 'execution_error',
            'risk_management', 'position_sizing' => 'risk_error',
            'trade_management', 'exit_quality' => 'management_error',
            'decision_discipline' => 'process_integrity_error',
            'statistical_research' => 'evidence_completion',
            'adaptation_ability' => 'adaptation_error',
            default => 'uncertain',
        };
    }
}

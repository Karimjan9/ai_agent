<?php

namespace App\Services;

/** Converts lane-specific failures into lane-specific training objectives. */
class TwinIntelligenceCurriculumService
{
    public const PROTOCOL = 'twin_intelligence_curriculum_v1';

    public function __construct(private TwinIntelligenceProfileService $profiles) {}

    /** @return array<string, mixed> */
    public function next(string $lane, ?string $failureClass = null, ?string $currentStage = null): array
    {
        $profile = $this->profiles->profile($lane);
        $curriculum = $profile['curriculum'];
        $stage = $this->stage($lane, $failureClass, $currentStage, $curriculum);
        $tasks = $lane === 'champion'
            ? match ($failureClass) {
                'wrong_entry', 'execution_loss' => ['replay_entry_timing', 'compare_slippage', 'run_frozen_control'],
                'bad_exit', 'drawdown_breach' => ['replay_exit_topology', 'stress_costs', 'run_temporal_holdout'],
                'regime_mismatch' => ['replay_declared_regime', 'test_abstention_boundary', 'run_out_of_sample_regimes'],
                default => ['declare_one_execution_hypothesis', 'change_one_gene', 'run_exact_control_pair'],
            }
            : match ($failureClass) {
                'false_consensus', 'groupthink', 'oversight_failure' => ['add_independent_red_team', 'run_leave_one_out', 'test_consensus_flip'],
                'false_veto', 'coverage_gap' => ['audit_veto_precision', 'test_regime_coverage', 'run_missed_opportunity_counterfactual'],
                'poor_calibration' => ['rebin_confidence', 'run_fresh_calibration_window', 'enforce_abstention_threshold'],
                default => ['declare_role_hypothesis', 'prove_member_complementarity', 'run_anchor_ablation'],
            };

        return [
            'protocol' => self::PROTOCOL, 'lane' => $lane, 'stage' => $stage,
            'failure_class' => $failureClass, 'objective' => $profile['learning_objective'],
            'tasks' => $tasks, 'mutation_budget' => $lane === 'champion' ? 'one_execution_gene' : 'one_role_or_topology_change',
            'exam' => $lane === 'champion' ? ['forward_windows' => 3, 'positive_windows' => 2, 'cost_stress' => true] : ['role_passport' => true, 'leave_one_out' => true, 'anchor_ablation' => true],
            'promotion_evidence' => false,
        ];
    }

    private function stage(string $lane, ?string $failureClass, ?string $currentStage, array $curriculum): string
    {
        if ($currentStage !== null && in_array($currentStage, $curriculum, true)) return $currentStage;
        if ($failureClass !== null) return $lane === 'champion' ? 'risk_control' : 'adversarial_critique';
        return (string) ($curriculum[0] ?? 'foundation');
    }
}

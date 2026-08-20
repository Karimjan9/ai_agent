<?php

namespace App\Services;

/** Immutable identity and development contract for the two organisms. */
class TwinIntelligenceProfileService
{
    public const PROTOCOL = 'twin_intelligence_profile_v1';

    /** @return array<string, mixed> */
    public function profile(string $lane): array
    {
        $lane = strtolower(trim($lane));
        if ($lane === 'champion') {
            return [
                'lane' => 'champion', 'identity' => 'execution_organism',
                'mission' => 'Find and execute robust, risk-adjusted opportunities with low friction.',
                'learning_objective' => 'execution_robustness',
                'memory_namespace' => 'champion.execution_memory',
                'reward_weights' => [
                    'profit' => .35, 'risk_adjusted_return' => .25, 'execution_quality' => .15,
                    'temporal_stability' => .15, 'cost_efficiency' => .10,
                ],
                'error_taxonomy' => [
                    'wrong_entry', 'late_entry', 'bad_exit', 'regime_mismatch',
                    'overtrading', 'cost_inefficiency', 'drawdown_breach', 'tail_risk',
                ],
                'curriculum' => [
                    'execution_basics', 'regime_adaptation', 'entry_exit_refinement',
                    'risk_control', 'temporal_stability', 'cost_aware_execution',
                    'out_of_sample_robustness', 'champion_certification',
                ],
                'lifecycle' => ['seed', 'trainee', 'challenger', 'forward_validated', 'paper', 'champion', 'retired'],
                'evolution_mode' => ['local_mutation', 'parameter_refinement', 'frozen_parent_differential', 'bounded_exploration'],
                'promotion_policy' => ['requires_forward_evidence' => true, 'requires_risk_gate' => true, 'requires_temporal_holdout' => true],
                'transfer_policy' => [
                    'can_receive' => ['risk_warning', 'regime_constraint', 'abstention_rule', 'validated_veto_condition'],
                    'can_send' => ['candidate_action', 'execution_plan', 'expected_edge', 'execution_feasibility'],
                    'status_transfer' => false,
                ],
            ];
        }

        if ($lane === 'council') {
            return [
                'lane' => 'council', 'identity' => 'reasoning_governance_organism',
                'mission' => 'Challenge, validate and govern decisions through diversity, dissent and calibrated abstention.',
                'learning_objective' => 'collective_reasoning_quality',
                'memory_namespace' => 'council.institutional_memory',
                'reward_weights' => [
                    'decision_quality' => .25, 'risk_avoided' => .20, 'useful_dissent' => .15,
                    'regime_coverage' => .15, 'calibration' => .15, 'complementarity' => .10,
                ],
                'error_taxonomy' => [
                    'false_consensus', 'groupthink', 'redundant_member', 'late_risk_detection',
                    'false_veto', 'missed_danger', 'poor_calibration', 'coverage_gap',
                ],
                'curriculum' => [
                    'role_apprenticeship', 'regime_specialization', 'disagreement_handling',
                    'abstention_discipline', 'adversarial_critique', 'council_compatibility',
                    'leave_one_out_validation', 'anchor_ablation', 'calibrated_certification',
                ],
                'lifecycle' => ['seed', 'role_apprentice', 'specialist_validated', 'role_certified', 'shadow_member', 'council_candidate', 'canary', 'active_member', 'retired'],
                'evolution_mode' => ['member_replacement', 'role_mutation', 'specialist_composition', 'diversity_optimization', 'adversarial_red_team', 'topology_mutation'],
                'promotion_policy' => [
                    'requires_role_passport' => true, 'requires_complementarity' => true,
                    'requires_leave_one_out' => true, 'requires_anchor_ablation' => true,
                ],
                'transfer_policy' => [
                    'can_receive' => ['candidate_action', 'execution_plan', 'execution_feasibility'],
                    'can_send' => ['risk_warning', 'regime_constraint', 'abstention_rule', 'validated_veto_condition'],
                    'status_transfer' => false,
                ],
            ];
        }

        throw new \InvalidArgumentException('Unknown twin intelligence lane: '.$lane);
    }

    /** @return array<string, mixed> */
    public function contract(string $lane): array
    {
        $profile = $this->profile($lane);
        return [
            'protocol' => self::PROTOCOL,
            'version' => (string) config('services.twin_intelligence.version', '1.0.0'),
            'profile_hash' => hash('sha256', json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
            'profile' => $profile,
            'promotion_evidence' => false,
        ];
    }
}

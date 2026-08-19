<?php

namespace App\Services;

/**
 * Deterministic apprenticeship teacher for Champion Council roles.
 *
 * The teacher allocates research tasks only. It cannot relax evidence gates
 * and it cannot promote an agent.
 */
class CouncilCurriculumService
{
    public const PROTOCOL = 'champion_council_curriculum_v1';

    /** @return array<string, mixed> */
    public function next(array $agent = [], array $failure = [], array $context = []): array
    {
        $role = (string) ($agent['role'] ?? data_get($agent, 'council_specialist_contract.role', 'unassigned'));
        $stage = (string) ($agent['stage'] ?? data_get($agent, 'evolution_stage.stage', 'apprentice'));
        $technical = (bool) ($failure['technical'] ?? data_get($failure, 'class', '') === 'technical');
        $disagreement = (bool) ($failure['council_disagreement'] ?? data_get($failure, 'type') === 'council_disagreement');
        $repeat = (int) ($failure['repeat_count'] ?? data_get($failure, 'repeat_failure_count', 0));

        if ($technical || $stage === 'foundation') {
            return $this->lesson('foundation', $role, 'restore_immutable_evidence', [
                'control_pair', 'replay_integrity', 'dataset_hash', 'execution_contract',
            ], ['mutation_allowed' => false]);
        }
        if ($disagreement) {
            return $this->lesson('adversarial', $role, 'calibrate_or_abstain_on_disagreement', [
                'router_calibration', 'challenger_window', 'wait_invariant',
            ], ['mutation_allowed' => false, 'action_on_disagreement' => 'WAIT']);
        }
        if ($repeat >= 2) {
            return $this->lesson('adversarial', $role, 'architecture_escape_for_repeat_failure', [
                'failure_signature', 'alternative_topology', 'independent_holdout',
            ], ['mutation_allowed' => true, 'max_changed_genes' => 3]);
        }
        if (in_array($stage, ['specialist_candidate', 'specialist_apprentice'], true)) {
            return $this->lesson('specialization', $role, 'prove_role_envelope_without_regression', [
                $this->roleGene($role), 'niche_control', 'no_regression',
            ], ['mutation_allowed' => true, 'max_changed_genes' => 1]);
        }
        if (in_array($stage, ['specialist_validated', 'mentor_candidate'], true)) {
            return $this->lesson('independent_confirmation', $role, 'confirm_skill_on_fresh_forward_windows', [
                'independent_forward_windows', 'falsification_condition', 'challenger',
            ], ['mutation_allowed' => false, 'minimum_windows' => 3, 'minimum_positive_windows' => 2]);
        }
        if ($stage === 'council_candidate') {
            return $this->lesson('council_validation', $role, 'prove_complementarity_and_replaceability', [
                'combined_replay', 'leave_one_out', 'weight_perturbation', 'router_stability',
            ], ['mutation_allowed' => false, 'promotion_requires_all_gates' => true]);
        }

        return $this->lesson('role_apprenticeship', $role, 'run_bounded_role_hypothesis', [
            $this->roleGene($role), 'exact_control_pair', 'falsification_condition',
        ], ['mutation_allowed' => true, 'max_changed_genes' => 1, 'research_only' => true], $context);
    }

    /** @return array<string, mixed> */
    private function lesson(string $stage, string $role, string $objective, array $tasks, array $rules, array $context = []): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'stage' => $stage,
            'role' => $role,
            'objective' => $objective,
            'tasks' => array_values(array_unique($tasks)),
            'rules' => [
                'research_only' => true,
                'promotion_evidence' => false,
                'documented_failure_cannot_repeat' => true,
                ...$rules,
            ],
            'context' => $context,
        ];
    }

    private function roleGene(string $role): string
    {
        return match ($role) {
            'trend_up_specialist', 'trend_down_specialist' => 'trend_strength_or_transition_firewall',
            'range_specialist' => 'range_deviation_or_reentry',
            'transition_risk_router' => 'transition_wait_or_abstention',
            'volume_session_specialist' => 'volume_session_filter',
            'cost_stability_specialist' => 'exit_or_cost_stability',
            default => 'declared_role_gene',
        };
    }
}

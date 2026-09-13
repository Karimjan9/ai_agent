<?php

namespace App\Services;

/** Deterministic acceptance worlds for the complete learning constitution. */
class CausalGoldenWorldHarnessService
{
    public const PROTOCOL = 'causal_golden_world_acceptance_v1';

    public function __construct(
        private CausalCapabilityLatticeService $lattice,
        private EvolutionaryAuthorityLadderService $authority,
    ) {}

    /** @return array<string,mixed> */
    public function run(): array
    {
        $componentFacts = $this->safeComponentFacts();
        $component = $this->lattice->evaluate([...$componentFacts, 'proof_carried' => false]);
        $composition = $this->lattice->evaluate([...$componentFacts,
            'composition_screening_passed' => true,
            'composition_full_replay_passed' => true,
            'composition_absolute_settlement' => .24,
        ]);
        $mentor = $this->authority->researchMentor([
            'failure_fingerprint' => 'golden:positive:target', 'target' => 'expectancy_margin',
            'exact_frozen_control' => true, 'gene' => 'cost_aware_exit', 'changed_gene_count' => 1,
            'context_hash' => 'london-trend', 'target_gate_improved' => true,
            'non_target_regression' => false, 'independence_verified' => true,
            'independent_windows' => 3, 'positive_windows' => 3, 'causal_skill_credit_count' => 1,
        ]);
        $parent = $this->authority->economicParent([
            'screening_passed' => true, 'full_replay_passed' => true,
            'positive_absolute_settlement' => true, 'forward_or_paper_evidence' => true,
            'performance_credit_count' => 1, 'improving_descendants' => 2,
            'inheritance_credit_count' => 2, 'context_trust_confirmed' => true,
        ], $mentor);
        $strongerChild = $this->lattice->evaluate([...$componentFacts,
            'composition_screening_passed' => true, 'composition_full_replay_passed' => true,
            'composition_absolute_settlement' => .24, 'forward_or_paper_evidence' => true,
            'improving_descendants' => 2, 'context_trust_confirmed' => true,
            'child_beats_parent' => true, 'child_beats_frozen_control' => true,
        ]);
        $positivePass = data_get($component, 'lattice_state') === 'causally_confirmed_component'
            && data_get($component, 'organism_viable') === false
            && data_get($composition, 'organism_viable') === true
            && data_get($mentor, 'eligible') === true
            && data_get($parent, 'eligible') === true
            && data_get($strongerChild, 'stronger_child') === true;

        $null = $this->lattice->evaluate([...$componentFacts,
            'beats_control' => false, 'beats_blinded' => false,
            'declared_target_improved' => false, 'positive_windows' => 0,
        ]);
        $nullPass = data_get($null, 'component_confirmed') === false
            && data_get($null, 'reproductive_authority') === false;

        $poisoned = $this->lattice->evaluate([...$componentFacts,
            'hard_risk_safe' => false, 'non_target_corridor_safe' => false,
            'composition_screening_passed' => true, 'composition_full_replay_passed' => true,
            'composition_absolute_settlement' => .40,
        ]);
        $poisonedPass = data_get($poisoned, 'component_confirmed') === false
            && data_get($poisoned, 'organism_viable') === false
            && data_get($poisoned, 'reproductive_authority') === false;

        $london = $this->lattice->evaluate($componentFacts);
        $asia = $this->lattice->evaluate([...$componentFacts,
            'context_and_gene_scoped' => false, 'hard_risk_safe' => false,
        ]);
        $contextSwitchPass = data_get($london, 'component_confirmed') === true
            && data_get($asia, 'component_confirmed') === false
            && data_get($asia, 'reproductive_authority') === false;

        $worlds = [
            'positive' => ['passed' => $positivePass, 'component' => $component,
                'composition' => $composition, 'mentor' => $mentor, 'economic_parent' => $parent,
                'stronger_child' => $strongerChild,
                'chain' => ['discover', 'confirm_component', 'shadow_capability', 'viable_composition',
                    'research_mentor', 'two_descendants', 'economic_parent', 'stronger_child', 'performance_and_inheritance_credit']],
            'null' => ['passed' => $nullPass, 'result' => $null,
                'false_confirmed' => 0, 'eligible_parent' => 0, 'search_action' => 'bounded_island_close'],
            'poisoned' => ['passed' => $poisonedPass, 'result' => $poisoned,
                'authority_action' => 'reject_and_preserve_as_negative_evidence'],
            'context_switch' => ['passed' => $contextSwitchPass, 'london' => $london, 'asia' => $asia,
                'london_action' => 'research_shadow_enabled', 'asia_action' => 'abstain',
                'global_inheritance_allowed' => false],
        ];
        $passed = collect($worlds)->every(fn (array $world): bool => $world['passed'] === true);

        return [
            'protocol' => self::PROTOCOL,
            'status' => $passed ? 'passed' : 'failed',
            'passed' => $passed,
            'worlds' => $worlds,
            'profit_guaranteed' => false,
            'wiring_and_false_authority_guards_proven' => $passed,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function safeComponentFacts(): array
    {
        return [
            'post_v2_epoch' => true, 'exact_frozen_control' => true,
            'intent_sealed_before_mutation' => true, 'beats_control' => true,
            'beats_blinded' => true, 'declared_target_improved' => true,
            'independent_windows' => 3, 'positive_windows' => 3,
            'hard_risk_safe' => true, 'non_target_corridor_safe' => true,
            'intent_run_outcome_linked' => true, 'context_and_gene_scoped' => true,
            'proof_carried' => true,
        ];
    }
}

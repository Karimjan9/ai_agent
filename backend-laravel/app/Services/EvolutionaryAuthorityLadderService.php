<?php

namespace App\Services;

/**
 * The constitutional boundary between local research guidance and genetic
 * reproduction.  This service is intentionally pure: callers may persist
 * its verdict, but no label or aggregate score can bypass its requirements.
 */
class EvolutionaryAuthorityLadderService
{
    public const PROTOCOL = 'two_tier_evolutionary_authority_v1';

    public const RESEARCH_MENTOR = 'research_mentor';

    public const ECONOMIC_PARENT = 'economic_parent';

    public const CREDIT_LADDER = [
        'information_credit',
        'repair_credit',
        'causal_skill_credit',
        'performance_credit',
        'inheritance_credit',
    ];

    /** @return array<string,mixed> */
    public function researchMentor(array $evidence): array
    {
        $windows = (int) data_get($evidence, 'independent_windows', 0);
        $positiveWindows = (int) data_get($evidence, 'positive_windows', 0);
        $checks = [
            'pre_registered_failure' => filled(data_get($evidence, 'failure_fingerprint'))
                && filled(data_get($evidence, 'target')),
            'exact_frozen_control' => data_get($evidence, 'exact_frozen_control') === true,
            'single_gene_scope' => filled(data_get($evidence, 'gene'))
                && (int) data_get($evidence, 'changed_gene_count', 0) === 1,
            'context_scope_bound' => filled(data_get($evidence, 'context_hash')),
            'target_gate_improved' => data_get($evidence, 'target_gate_improved') === true,
            'non_target_gates_preserved' => data_get($evidence, 'non_target_regression') === false,
            'independent_replication' => data_get($evidence, 'independence_verified') === true
                && $windows >= 3
                && $positiveWindows >= 2,
            'causal_skill_credit_earned' => (int) data_get($evidence, 'causal_skill_credit_count', 0) >= 1,
        ];
        $failed = collect($checks)->filter(fn (bool $passed): bool => ! $passed)->keys()->values()->all();

        return [
            'protocol' => self::PROTOCOL,
            'tier' => self::RESEARCH_MENTOR,
            'eligible' => $failed === [],
            'status' => $failed === [] ? 'granted_local_repair_authority' : 'withheld',
            'checks' => $checks,
            'reason_codes' => array_map(fn (string $key): string => 'RESEARCH_MENTOR_'.strtoupper($key).'_REQUIRED', $failed),
            'scope' => [
                'failure_fingerprint' => data_get($evidence, 'failure_fingerprint'),
                'target' => data_get($evidence, 'target'),
                'gene' => data_get($evidence, 'gene'),
                'context_hash' => data_get($evidence, 'context_hash'),
            ],
            'allowed_actions' => ['guide_next_bounded_repair', 'replication', 'factorial_ablation'],
            'forbidden_actions' => ['global_genetic_parent', 'cross_context_inheritance', 'paper_or_live_admission'],
            'parent_eligible' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function economicParent(array $evidence, array $mentorAuthority): array
    {
        $checks = [
            'research_mentor_authority' => data_get($mentorAuthority, 'eligible') === true,
            'screening_passed' => data_get($evidence, 'screening_passed') === true,
            'full_replay_passed' => data_get($evidence, 'full_replay_passed') === true,
            'positive_absolute_settlement' => data_get($evidence, 'positive_absolute_settlement') === true,
            'forward_or_paper_evidence' => data_get($evidence, 'forward_or_paper_evidence') === true,
            'performance_credit_earned' => (int) data_get($evidence, 'performance_credit_count', 0) >= 1,
            'two_improving_descendants' => (int) data_get($evidence, 'improving_descendants', 0) >= 2,
            'two_inheritance_credits_earned' => (int) data_get($evidence, 'inheritance_credit_count', 0) >= 2,
            'context_trust_confirmed' => data_get($evidence, 'context_trust_confirmed') === true,
        ];
        $failed = collect($checks)->filter(fn (bool $passed): bool => ! $passed)->keys()->values()->all();

        return [
            'protocol' => self::PROTOCOL,
            'tier' => self::ECONOMIC_PARENT,
            'eligible' => $failed === [],
            'status' => $failed === [] ? 'granted_genetic_reproduction_authority' : 'withheld',
            'checks' => $checks,
            'reason_codes' => array_map(fn (string $key): string => 'ECONOMIC_PARENT_'.strtoupper($key).'_REQUIRED', $failed),
            'allowed_actions' => $failed === []
                ? ['context_bound_genetic_parent', 'descendant_reproduction', 'paper_candidate_handoff']
                : [],
            'scope' => (array) data_get($mentorAuthority, 'scope', []),
            'inheritance_scope' => 'same_context_and_confirmed_gene_only',
            'parent_eligible' => $failed === [],
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function creditConstitution(): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'ladder' => self::CREDIT_LADDER,
            'experiment_routing_credits' => ['information_credit', 'repair_credit'],
            'research_mentor_credit' => 'causal_skill_credit',
            'economic_parent_credits' => ['performance_credit', 'inheritance_credit'],
            'early_credit_authority' => 'experiment_allocation_only',
            'credit_never_implies_promotion' => true,
            'promotion_requires_explicit_authority' => true,
            'promotion_evidence' => false,
        ];
    }

    /**
     * Ten exact candidate/control pairs. Before a stepping stone, twelve
     * seats repair known deficits, six buy structural novelty and two guard
     * continuity. Afterwards compute moves to replication, interaction and
     * descendant challenges without deleting the repair/novelty floor.
     *
     * @return array<int,array<string,mixed>>
     */
    public function experimentBlocks(bool $steppingStoneAvailable): array
    {
        $types = $steppingStoneAvailable
            ? [
                'replication', 'replication', 'replication',
                'factorial_interaction', 'factorial_interaction',
                'descendant_challenge', 'descendant_challenge',
                'exact_repair', 'structural_novelty', 'continuity_adversarial_guard',
            ]
            : [
                'exact_repair', 'exact_repair', 'exact_repair',
                'exact_repair', 'exact_repair', 'exact_repair',
                'structural_novelty', 'structural_novelty', 'structural_novelty',
                'continuity_adversarial_guard',
            ];

        return collect($types)->map(function (string $type, int $index) use ($steppingStoneAvailable): array {
            return [
                'protocol' => self::PROTOCOL,
                'block_index' => $index + 1,
                'block_type' => $type,
                'seat_count' => 2,
                'pair_roles' => ['exact_frozen_control', 'candidate'],
                'phase' => $steppingStoneAvailable ? 'causal_compounding' : 'cold_start',
                'failure_or_lesson_must_be_sealed_before_mutation' => in_array($type, ['exact_repair', 'replication'], true),
                'research_only' => true,
                'promotion_evidence' => false,
            ];
        })->all();
    }
}

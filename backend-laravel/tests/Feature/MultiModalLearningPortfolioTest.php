<?php

namespace Tests\Feature;

use App\Services\EvolutionaryAuthorityLadderService;
use App\Services\MultiModalLearningPortfolioService;
use Tests\TestCase;

class MultiModalLearningPortfolioTest extends TestCase
{
    public function test_question_fidelity_is_bounded_diagnostic_and_preserves_independent_admission(): void
    {
        $owner = app(MultiModalLearningPortfolioService::class);
        foreach (['semantic', 'diagnostic', 'discovery', 'replication', 'independent_validation', 'descendant_proof'] as $kind) {
            $plan = $owner->planFidelity(['kind' => $kind, 'question_key' => 'question',
                'budget' => ['max_evaluated_events' => PHP_INT_MAX, 'max_compute_seconds' => PHP_INT_MAX]]);
            $this->assertLessThanOrEqual(15000, $plan['budget']['max_evaluated_events']);
            $this->assertLessThanOrEqual(900, $plan['budget']['max_compute_seconds']);
            $this->assertSame(1, $plan['budget']['max_experiments']);
            $this->assertSame('not_executed', $plan['execution_status']);
            $this->assertFalse($plan['cheap_negative_is_final_skill_verdict']);
            $this->assertFalse($plan['independence_attested']);
            $this->assertFalse($plan['promotion_evidence']);
        }
        $this->assertSame('blocked_dependency', $owner->planFidelity(['kind' => 'independent_validation'])['status']);
        $this->assertSame('blocked_dependency', $owner->planFidelity(['kind' => 'unknown'])['status']);
        $this->assertSame('LEARNING_PROGRESS_HOLD_BUDGET', $owner->planFidelity(['kind' => 'discovery',
            'learning_progress' => ['recommendation' => ['action' => 'hold_budget']]])['dependency']);
    }

    public function test_native_allocator_ranks_only_ready_compatible_questions_and_seals_discriminating_probe(): void
    {
        $owner = app(MultiModalLearningPortfolioService::class);
        $questions = [
            ['question_id' => 'ready-diagnostic', 'learning_method' => 'bayesian_active_learning', 'kind' => 'diagnostic',
                'ready' => true, 'safety_preserved' => true, 'cost_ceiling_seconds' => 20, 'features' => ['expected_value' => .7],
                'forecast_spec' => ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'family' => 'trend', 'target' => 'entry',
                    'gene' => 'entry_gate', 'baseline_hash' => 'baseline', 'data_hash' => 'data', 'execution_hash' => 'execution', 'runtime_hash' => 'runtime']],
            ['question_id' => 'unready-expensive', 'learning_method' => 'quality_diversity_novelty', 'kind' => 'discovery',
                'ready' => false, 'safety_preserved' => true, 'cost_ceiling_seconds' => 300, 'features' => ['expected_value' => 1]],
            ['question_id' => 'wrong-seat', 'learning_method' => 'positive_skill_replication', 'kind' => 'replication',
                'ready' => true, 'safety_preserved' => true, 'cost_ceiling_seconds' => 20, 'features' => ['expected_value' => 1]],
        ];
        $block = ['block_type' => 'structural_novelty', 'question_candidates' => $questions, 'question_selection_seed' => 'frozen-seed',
            'question_scope' => ['question_key' => 'q', 'data_hash' => 'data', 'baseline_hash' => 'baseline'],
            'competing_hypotheses' => [['id' => 'A', 'prior' => .5], ['id' => 'B', 'prior' => .5]],
            'discriminating_probes' => [['id' => 'semantic', 'legal' => true, 'safety_preserved' => true,
                'cost_ceiling_seconds' => 2, 'predictions' => ['A' => ['yes' => 1], 'B' => ['no' => 1]]]]];
        $plan = $owner->allocate([$block]);
        $chosen = $plan['blocks'][0];
        $this->assertSame('bayesian_active_learning', $chosen['learning_method']);
        $this->assertSame('ready-diagnostic', $chosen['fidelity_plan']['question_key']);
        $this->assertSame(20, $chosen['fidelity_plan']['budget']['max_compute_seconds']);
        $this->assertCount(1, $chosen['question_selection']['ranking']);
        $this->assertSame('prior_only', $chosen['question_selection']['prospective_forecast']['status']);
        $this->assertSame('exact_context_research_boundary_map', $chosen['question_selection']['applicability_map']['status']);
        $this->assertSame([], $chosen['question_selection']['applicability_map']['cells']);
        $this->assertSame('semantic', $chosen['discriminating_probe_plan']['selected_probe']);
        $this->assertSame($plan, $owner->allocate([$block]));
        $this->assertFalse($chosen['promotion_evidence']);
        $this->assertTrue($chosen['requires_exact_frozen_control']);
    }

    public function test_default_native_generation_identity_activates_same_seat_heuristic_ranking_without_inventing_forecast(): void
    {
        $owner = app(MultiModalLearningPortfolioService::class);
        $blocks = app(EvolutionaryAuthorityLadderService::class)->experimentBlocks(false);
        $identity = ['laboratory_id' => 7, 'generation_number' => 12, 'symbol' => 'XAUUSD', 'timeframe' => 'H1'];
        $plan = $owner->allocate($blocks, ['open_failure_count' => 2], $identity);
        foreach ($plan['blocks'] as $index => $block) {
            $this->assertSame($identity, $block['question_selection']['planning_identity']);
            $this->assertNotEmpty($block['question_selection']['ranking']);
            $this->assertFalse($block['question_selection']['heuristic_is_forecast_evidence']);
            $this->assertSame($blocks[$index]['block_type'], $block['authority_block_type']);
            $this->assertFalse($block['promotion_evidence']);
            $this->assertSame('ACTUAL_COMPETING_HYPOTHESES_AND_PROBE_SPEC_REQUIRED', $block['hypothesis_dependency']);
        }
        $this->assertSame($plan, $owner->allocate($blocks, ['open_failure_count' => 2], $identity));
        $legacy = $owner->allocate($blocks);
        $this->assertNull($legacy['blocks'][0]['question_selection']);
        $this->assertNotNull($legacy['blocks'][0]['planning_dependency']);
    }

    public function test_cold_start_uses_four_learning_sources_without_weakening_exact_pairs(): void
    {
        $blocks = app(EvolutionaryAuthorityLadderService::class)->experimentBlocks(false);
        $plan = app(MultiModalLearningPortfolioService::class)->allocate($blocks, [
            'open_failure_count' => 4,
            'information_credit' => 12,
            'context_coverage_deficit' => 4,
        ]);

        $this->assertSame('allocated', $plan['status']);
        $this->assertSame(10, array_sum($plan['pair_allocations']));
        $this->assertContains('failure_directed_repair', $plan['active_methods']);
        $this->assertContains('bayesian_active_learning', $plan['active_methods']);
        $this->assertContains('quality_diversity_novelty', $plan['active_methods']);
        $this->assertContains('adversarial_robustness', $plan['active_methods']);
        $this->assertGreaterThanOrEqual(4, $plan['method_diversity']);
        $this->assertTrue(data_get($plan, 'constitution.failure_learning_is_one_method_not_the_only_method'));
        $this->assertTrue(data_get($plan, 'constitution.best_known_specialist_is_monotonic'));

        foreach ($plan['blocks'] as $index => $block) {
            $this->assertSame($blocks[$index]['block_type'], $block['authority_block_type']);
            $this->assertTrue($block['requires_exact_frozen_control']);
            $this->assertTrue($block['selection_must_precede_mutation']);
            $this->assertTrue($block['research_nursery_only']);
            $this->assertFalse($block['confirmed_elite_replacement_allowed']);
            $this->assertFalse($block['promotion_evidence']);
        }
    }

    public function test_positive_evidence_moves_compute_to_replication_factorial_transfer_and_rehearsal(): void
    {
        $blocks = app(EvolutionaryAuthorityLadderService::class)->experimentBlocks(true);
        $plan = app(MultiModalLearningPortfolioService::class)->allocate($blocks, [
            'open_failure_count' => 2,
            'information_credit' => 20,
            'repair_credit' => 4,
            'causal_skill_credit' => 2,
            'confirmed_skill_count' => 2,
            'research_mentor_count' => 1,
            'economic_parent_count' => 1,
            'pending_transfer_count' => 9,
            'context_coverage_deficit' => 2,
        ]);

        $this->assertContains('positive_skill_replication', $plan['active_methods']);
        $this->assertContains('counterfactual_factorial', $plan['active_methods']);
        $this->assertContains('context_transfer_validation', $plan['active_methods']);
        $this->assertContains('elite_rehearsal_guard', $plan['active_methods']);
        $this->assertGreaterThanOrEqual(6, $plan['method_diversity']);
        $this->assertSame(2, $plan['pair_allocations']['counterfactual_factorial']);
        $this->assertSame(2, $plan['pair_allocations']['context_transfer_validation']);
        $this->assertSame(1, $plan['pair_allocations']['elite_rehearsal_guard']);
        $this->assertTrue(data_get($plan, 'constitution.local_skill_never_becomes_global_authority_by_transfer'));
        foreach (['counterfactual_factorial', 'context_transfer_validation'] as $method) {
            $methodBlocks = collect($plan['blocks'])->where('learning_method', $method)->values();
            $this->assertCount(2, $methodBlocks);
            $this->assertSame(
                data_get($methodBlocks[0], 'experiment_topology.group_key'),
                data_get($methodBlocks[1], 'experiment_topology.group_key'),
            );
            $this->assertSame([1, 2], $methodBlocks->pluck('experiment_topology.pair_ordinal')->all());
            $this->assertTrue($methodBlocks->every(
                fn (array $block): bool => data_get($block, 'experiment_topology.status') === 'complete',
            ));
        }
    }
}

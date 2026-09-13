<?php

namespace Tests\Feature;

use App\Services\EvolutionaryAuthorityLadderService;
use App\Services\MultiModalLearningPortfolioService;
use Tests\TestCase;

class MultiModalLearningPortfolioTest extends TestCase
{
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

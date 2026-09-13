<?php

namespace Tests\Feature;

use App\Services\EvolutionaryAuthorityLadderService;
use Tests\TestCase;

class EvolutionaryAuthorityLadderTest extends TestCase
{
    public function test_research_mentor_is_local_and_does_not_require_profitability(): void
    {
        $authority = app(EvolutionaryAuthorityLadderService::class)->researchMentor([
            'failure_fingerprint' => str_repeat('f', 64),
            'target' => 'stress_cost',
            'gene' => 'max_spread_atr_ratio',
            'changed_gene_count' => 1,
            'context_hash' => str_repeat('c', 64),
            'exact_frozen_control' => true,
            'target_gate_improved' => true,
            'non_target_regression' => false,
            'independence_verified' => true,
            'independent_windows' => 3,
            'positive_windows' => 2,
            'causal_skill_credit_count' => 1,
            // No economic/PnL field is intentionally present.
        ]);

        $this->assertTrue($authority['eligible']);
        $this->assertSame('research_mentor', $authority['tier']);
        $this->assertFalse($authority['parent_eligible']);
        $this->assertContains('global_genetic_parent', $authority['forbidden_actions']);
        $this->assertSame('experiment_allocation_only', data_get(
            app(EvolutionaryAuthorityLadderService::class)->creditConstitution(),
            'early_credit_authority',
        ));
    }

    public function test_economic_parent_requires_every_economic_and_reproductive_gate(): void
    {
        $service = app(EvolutionaryAuthorityLadderService::class);
        $mentor = ['eligible' => true];
        $base = [
            'screening_passed' => true,
            'full_replay_passed' => true,
            'positive_absolute_settlement' => false,
            'forward_or_paper_evidence' => true,
            'performance_credit_count' => 1,
            'improving_descendants' => 2,
            'inheritance_credit_count' => 2,
            'context_trust_confirmed' => true,
        ];

        $withheld = $service->economicParent($base, $mentor);
        $granted = $service->economicParent([...$base, 'positive_absolute_settlement' => true], $mentor);

        $this->assertFalse($withheld['eligible']);
        $this->assertContains('ECONOMIC_PARENT_POSITIVE_ABSOLUTE_SETTLEMENT_REQUIRED', $withheld['reason_codes']);
        $this->assertTrue($granted['eligible']);
        $this->assertSame('economic_parent', $granted['tier']);
        $this->assertTrue($granted['parent_eligible']);

        $missingCredit = $service->economicParent([
            ...$base,
            'positive_absolute_settlement' => true,
            'performance_credit_count' => 0,
        ], $mentor);
        $this->assertFalse($missingCredit['eligible']);
        $this->assertContains('ECONOMIC_PARENT_PERFORMANCE_CREDIT_EARNED_REQUIRED', $missingCredit['reason_codes']);
    }

    public function test_twenty_seat_cold_start_and_compounding_allocations_are_explicit(): void
    {
        $service = app(EvolutionaryAuthorityLadderService::class);
        $cold = collect($service->experimentBlocks(false));
        $warm = collect($service->experimentBlocks(true));

        $this->assertSame(20, $cold->sum('seat_count'));
        $this->assertSame(12, $cold->where('block_type', 'exact_repair')->sum('seat_count'));
        $this->assertSame(6, $cold->where('block_type', 'structural_novelty')->sum('seat_count'));
        $this->assertSame(2, $cold->where('block_type', 'continuity_adversarial_guard')->sum('seat_count'));
        $this->assertSame(20, $warm->sum('seat_count'));
        $this->assertGreaterThan(0, $warm->where('block_type', 'replication')->sum('seat_count'));
        $this->assertGreaterThan(0, $warm->where('block_type', 'factorial_interaction')->sum('seat_count'));
        $this->assertGreaterThan(0, $warm->where('block_type', 'descendant_challenge')->sum('seat_count'));
    }
}

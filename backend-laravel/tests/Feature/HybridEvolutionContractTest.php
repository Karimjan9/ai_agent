<?php

namespace Tests\Feature;

use App\Services\HybridEvolutionContractService;
use App\Services\StructuralResearchCohortService;
use App\Models\AiLaboratory;
use Tests\TestCase;

class HybridEvolutionContractTest extends TestCase
{
    public function test_twenty_seat_cohort_reserves_controls_and_uses_exact_research_budget(): void
    {
        $contract = app(HybridEvolutionContractService::class)->allocation(20, 2);

        $this->assertSame(20, $contract['population']);
        $this->assertSame(2, $contract['control_seats']);
        $this->assertSame(18, $contract['research_seats']);
        $this->assertSame([
            'directed_repair' => 11,
            'bold_structural' => 4,
            'adversarial_escape' => 3,
        ], $contract['counts']);
        $this->assertFalse((bool) $contract['promotion_evidence']);
    }

    public function test_structural_cohort_gets_deterministic_lanes_and_multi_gene_contracts(): void
    {
        $plan = [];
        foreach (['regime_coverage', 'monthly_survival', 'volatility_session_stability', 'exit_topology', 'portfolio_router'] as $group) {
            for ($seat = 1; $seat <= 4; $seat++) {
                $plan[] = [
                    'family' => $group === 'portfolio_router' ? 'differential_router' : 'hybrid',
                    'target' => $group,
                    'research_group' => $group,
                    'niche' => [
                        'declared_gene' => 'entry_topology_variant',
                        'declared_value' => 'regime_consensus_v1',
                        'entry_topology_variant' => 'regime_consensus_v1',
                        'control_only' => ($group === 'exit_topology' || $group === 'portfolio_router') && $seat === 4,
                    ],
                ];
            }
        }

        $result = app(HybridEvolutionContractService::class)->decoratePlan($plan, 'cohort-test');
        $decorated = collect($result['plan']);

        $this->assertSame(4, $decorated->where('niche.hybrid_evolution_lane', 'bold_structural')->count());
        $this->assertSame(3, $decorated->where('niche.hybrid_evolution_lane', 'adversarial_escape')->count());
        $this->assertSame(11, $decorated->where('niche.hybrid_evolution_lane', 'directed_repair')->count());
        $this->assertSame(2, $decorated->where('niche.hybrid_evolution_lane', 'frozen_control')->count());

        $bold = $decorated->firstWhere('niche.hybrid_evolution_lane', 'bold_structural');
        $this->assertCount(2, (array) data_get($bold, 'niche.declared_genes'));
        $this->assertSame(3, data_get($bold, 'niche.hybrid_evolution_contract.max_changed_genes'));
        $this->assertTrue((bool) data_get($bold, 'niche.hybrid_evolution_contract.research_only_until_independent_confirmation'));
        $this->assertFalse((bool) data_get($bold, 'niche.hybrid_evolution_contract.promotion_evidence'));
    }

    public function test_failure_policy_converts_repeat_failure_to_direction_closing_escape(): void
    {
        $policy = app(HybridEvolutionContractService::class)->failureAction('repeated_failure');

        $this->assertSame('adversarial_escape', $policy['lane']);
        $this->assertSame('close_direction_and_architecture_escape', $policy['action']);
        $this->assertSame(3, $policy['max_changed_genes']);
        $this->assertSame('no_same_direction_reuse', $policy['repeat_policy']);
        $this->assertFalse((bool) $policy['promotion_evidence']);
    }

    public function test_real_structural_rescue_plan_contains_executable_multi_gene_hypotheses(): void
    {
        $lab = new AiLaboratory([
            'id' => 62,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid', 'differential_router'],
        ]);
        $plan = app(StructuralResearchCohortService::class)->plan($lab, ['source_generation_id' => 62, 'profile_hash' => 'test']);
        $decorated = collect($plan);

        $this->assertCount(20, $plan);
        $this->assertSame(2, $decorated->where('niche.hybrid_evolution_lane', 'frozen_control')->count());
        $this->assertSame(4, $decorated->where('niche.hybrid_evolution_lane', 'bold_structural')->count());
        $this->assertSame(3, $decorated->where('niche.hybrid_evolution_lane', 'adversarial_escape')->count());
        $this->assertTrue($decorated->every(fn (array $seat): bool => data_get($seat, 'niche.control_only')
            || (bool) data_get($seat, 'niche.structural_research')));
        $this->assertCount(2, (array) data_get($decorated->firstWhere('niche.hybrid_evolution_lane', 'bold_structural'), 'niche.declared_genes'));
        $this->assertSame(1.25, data_get($decorated->firstWhere('niche.declared_gene', 'atr_stop_multiplier'), 'niche.declared_value'));
        $this->assertSame(.25, data_get($decorated->firstWhere('niche.declared_gene', 'partial_take_profit_fraction'), 'niche.declared_value'));
    }
}

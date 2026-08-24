<?php

namespace Tests\Feature;

use App\Services\StrategyTacticRiskCompositionPlannerService;
use Tests\TestCase;

class StrategyTacticRiskCompositionPlannerTest extends TestCase
{
    public function test_full_cohort_receives_auditable_6_6_5_3_composition_contract(): void
    {
        $plan = collect(range(1, 20))->map(fn (int $slot): array => [
            'family' => $slot % 2 ? 'hybrid' : 'differential_router',
            'target' => 'portfolio_router',
            'niche' => $slot <= 3 ? ['structural_research' => true] : [],
        ])->all();

        $result = app(StrategyTacticRiskCompositionPlannerService::class)->materialize($plan);

        $this->assertSame('admitted', $result['contract']['status']);
        $this->assertSame([
            'strategy_composition' => 6,
            'tactic_mutation' => 6,
            'risk_management_mutation' => 5,
            'structural_topology_experiment' => 3,
        ], $result['contract']['budget']);
        $lanes = collect($result['plan'])->countBy(fn (array $seat): string => (string) data_get($seat, 'niche.composition_lane'));
        $this->assertSame(6, $lanes['strategy_composition']);
        $this->assertSame(6, $lanes['tactic_mutation']);
        $this->assertSame(5, $lanes['risk_management_mutation']);
        $this->assertSame(3, $lanes['structural_topology_experiment']);
        $this->assertCount(5, collect($result['plan'])->filter(fn (array $seat): bool => data_get($seat, 'niche.risk_library_contract.paired_control_required') === true));
        $strategySeat = collect($result['plan'])->firstWhere('niche.composition_lane', 'strategy_composition');
        $tacticSeat = collect($result['plan'])->firstWhere('niche.composition_lane', 'tactic_mutation');
        $this->assertContains($strategySeat['family'], ['trend', 'breakout', 'volatility', 'mean_reversion', 'session', 'hybrid']);
        $this->assertNotEmpty(data_get($strategySeat, 'niche.composition_architecture'));
        $this->assertSame('trend', $tacticSeat['family']);
        $this->assertSame('trend_pullback', data_get($tacticSeat, 'niche.composition_architecture'));
    }

    public function test_recovery_cohort_is_not_silently_rewritten_as_a_full_composition_cohort(): void
    {
        $result = app(StrategyTacticRiskCompositionPlannerService::class)->materialize(array_fill(0, 4, ['niche' => []]));

        $this->assertSame('not_applicable_bounded_cohort', $result['contract']['status']);
    }
}

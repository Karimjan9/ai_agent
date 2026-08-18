<?php

namespace Tests\Feature;

use App\Services\ResearchAllocationPolicyService;
use App\Services\StrategyParameterSchemaService;
use App\Services\TacticCatalogueService;
use App\Models\AiLaboratory;
use Tests\TestCase;

class NormalCausalResearchContractTest extends TestCase
{
    public function test_normal_plan_materializes_exact_controls_and_keeps_structural_candidate(): void
    {
        $plan = [
            [
                'family' => 'hybrid',
                'target' => 'regime_coverage',
                'niche' => ['structural_research' => true, 'declared_gene' => 'entry_topology_variant'],
            ],
            [
                'family' => 'hybrid',
                'target' => 'monthly_survival',
                'niche' => [],
            ],
            [
                'family' => 'differential_router',
                'target' => 'regime_coverage',
                'niche' => ['structural_research' => true, 'declared_gene' => 'regime_classifier_variant'],
            ],
            [
                'family' => 'differential_router',
                'target' => 'monthly_survival',
                'niche' => [],
            ],
        ];

        $result = app(ResearchAllocationPolicyService::class)->materializeNormalControlPairing(
            $plan,
            'XAUUSD',
            'H1',
            123,
        );

        $this->assertTrue((bool) data_get($result, 'contract.allowed'));
        $this->assertCount(2, data_get($result, 'contract.materialized_controls'));
        $this->assertSame([], data_get($result, 'contract.missing_candidate_pairs'));

        foreach ((array) data_get($result, 'plan') as $slot) {
            $this->assertSame('frozen_control_pair_v1', data_get($slot, 'niche.control_pair_contract.protocol'));
            $this->assertNotEmpty(data_get($slot, 'niche.control_pair_contract.pair_key'));
        }
    }

    public function test_structural_genes_are_schema_and_tactic_declared(): void
    {
        $schema = app(StrategyParameterSchemaService::class);
        $parameters = $schema->defaults('hybrid');
        $parameters['regime_classifier_variant'] = 'volatility_adaptive_v1';
        $schema->validate('hybrid', $parameters);

        $alignment = app(TacticCatalogueService::class)->alignment(
            app(TacticCatalogueService::class)->for('hybrid', 'regime_router', 'regime_coverage'),
            'regime_coverage',
            'regime_classifier_variant',
        );

        $this->assertSame('passed', $alignment['status']);
    }

    public function test_normal_structural_compiler_reserves_executable_hypotheses(): void
    {
        $service = app(\App\Services\LabPopulationService::class);
        $reflection = new \ReflectionMethod($service, 'normalStructuralResearchPlan');
        $reflection->setAccessible(true);
        $lab = new AiLaboratory(['strategy_families' => ['hybrid', 'differential_router']]);
        $plan = [];
        for ($index = 0; $index < 20; $index++) {
            $plan[] = [
                'family' => $index < 10 ? 'hybrid' : 'differential_router',
                'target' => 'regime_coverage',
                'niche' => [],
            ];
        }

        $result = $reflection->invoke($service, $plan, $lab);
        $structural = collect((array) data_get($result, 'plan'))
            ->filter(fn (array $slot): bool => (bool) data_get($slot, 'niche.structural_research', false));

        $this->assertGreaterThanOrEqual(4, $structural->count());
        $this->assertTrue($structural->pluck('niche.declared_gene')->contains('entry_topology_variant'));
        $this->assertTrue($structural->pluck('niche.declared_gene')->contains('regime_classifier_variant'));
        $this->assertTrue($structural->pluck('niche.declared_gene')->contains('state_machine_variant'));
    }

    public function test_lone_volume_lane_is_materialized_as_a_control_candidate_pair(): void
    {
        $result = app(ResearchAllocationPolicyService::class)->materializeNormalControlPairing(
            [
                [
                    'family' => 'differential_router',
                    'target' => 'regime_coverage',
                    'niche' => [
                        'volume_shadow' => true,
                        'shadow_only' => true,
                        'shadow_mutation_gene' => 'volume_lane',
                    ],
                ],
                [
                    'family' => 'differential_router',
                    'target' => 'profit_factor',
                    'niche' => [],
                ],
            ],
            'XAUUSD',
            'H1',
            456,
        );

        $this->assertTrue((bool) data_get($result, 'contract.allowed'));
        $this->assertSame([], data_get($result, 'contract.missing_candidate_pairs'));
        $this->assertNotNull(data_get($result, 'contract.volume_pair_repair'));
        $this->assertSame(1, data_get($result, 'contract.candidate_counts.volume|differential_router'));
        $this->assertSame('volume', data_get($result, 'plan.1.niche.data_lane'));
        $this->assertSame('volume_lane', data_get($result, 'plan.1.niche.shadow_mutation_gene'));
    }
}

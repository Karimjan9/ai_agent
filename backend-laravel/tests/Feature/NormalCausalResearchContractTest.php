<?php

namespace Tests\Feature;

use App\Console\Commands\DispatchLabGeneration;
use App\Models\AiLaboratory;
use App\Services\LabPopulationService;
use App\Services\ResearchAllocationPolicyService;
use App\Services\StrategyParameterSchemaService;
use App\Services\TacticCatalogueService;
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
        $this->assertTrue((bool) data_get($result, 'contract.one_control_per_candidate'));

        foreach ((array) data_get($result, 'plan') as $slot) {
            $this->assertSame(ResearchAllocationPolicyService::CONTROL_PAIR_PROTOCOL, data_get($slot, 'niche.control_pair_contract.protocol'));
            $this->assertNotEmpty(data_get($slot, 'niche.control_pair_contract.pair_key'));
        }
        $pairs = collect((array) data_get($result, 'plan'))->groupBy('niche.control_pair_contract.pair_key');
        $this->assertCount(2, $pairs);
        $this->assertTrue($pairs->every(fn ($seats): bool => $seats->count() === 2
            && $seats->pluck('niche.control_pair_contract.role')->sort()->values()->all() === ['candidate', 'control']));
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
        $service = app(LabPopulationService::class);
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
        $this->assertSame(1, data_get($result, 'contract.candidate_counts.volume|differential_router'));
        $this->assertSame('volume', data_get($result, 'plan.1.niche.data_lane'));
        $this->assertSame('volume_lane', data_get($result, 'plan.1.niche.shadow_mutation_gene'));
    }

    public function test_volume_repair_preserves_the_source_price_family_pair(): void
    {
        $result = app(ResearchAllocationPolicyService::class)->materializeNormalControlPairing(
            [
                [
                    'family' => 'mean_reversion',
                    'target' => 'regime_coverage',
                    'niche' => ['volume_shadow' => true, 'shadow_only' => true],
                ],
                ['family' => 'mean_reversion', 'target' => 'profit_factor', 'niche' => []],
                ['family' => 'mean_reversion', 'target' => 'monthly_survival', 'niche' => []],
                [
                    'family' => 'hybrid',
                    'target' => 'stress_cost',
                    'niche' => ['composition_lane' => 'risk_management_mutation'],
                ],
                ['family' => 'hybrid', 'target' => 'profit_factor', 'niche' => []],
                ['family' => 'hybrid', 'target' => 'monthly_survival', 'niche' => []],
            ],
            'XAUUSD',
            'H1',
            789,
        );

        $this->assertTrue((bool) data_get($result, 'contract.allowed'));
        $this->assertSame([], data_get($result, 'contract.missing_candidate_pairs'));
        $this->assertTrue((bool) data_get($result, 'contract.one_control_per_candidate'));
        $counts = (array) data_get($result, 'contract.candidate_counts', []);
        $this->assertSame(2, $counts['price|mean_reversion'] ?? null, json_encode($counts));
        $this->assertSame(1, $counts['volume|mean_reversion'] ?? null, json_encode($counts));
    }

    public function test_three_proof_seats_leave_eight_exact_pairs_and_one_explicit_abstention(): void
    {
        $plan = [];
        foreach (range(1, 20) as $seat) {
            $plan[] = [
                'family' => 'hybrid',
                'origin' => 'test',
                'target' => 'profit_factor',
                'niche' => $seat <= 3 ? [
                    'causal_learning_cohort' => [
                        'protocol' => 'causal_triplet_constructor_v4',
                        'role' => ['memory_guided', 'blinded', 'frozen_control'][$seat - 1],
                    ],
                ] : ['declared_gene' => 'minimum_signal_confidence'],
            ];
        }

        $result = app(ResearchAllocationPolicyService::class)->materializeNormalControlPairing(
            $plan,
            'XAUUSD',
            'H1',
            999,
        );

        $this->assertTrue((bool) data_get($result, 'contract.allowed'));
        $this->assertSame([1, 2, 3], data_get($result, 'contract.primary_proof_slots'));
        $this->assertSame(8, data_get($result, 'contract.pair_count'));
        $this->assertCount(8, data_get($result, 'contract.materialized_controls'));
        $this->assertSame([20], data_get($result, 'contract.uncertainty_abstain_slots'));
        $this->assertSame('memory_guided', data_get($result, 'plan.0.niche.causal_learning_cohort.role'));
        $this->assertSame('uncertainty_abstain', data_get($result, 'plan.19.niche.control_pair_contract.role'));
        $this->assertTrue((bool) data_get($result, 'plan.19.niche.control_only'));
    }

    public function test_legacy_core_fill_cannot_replace_a_seeded_causal_repair_triplet(): void
    {
        $roles = ['repair_guided', 'blinded', 'frozen_control'];
        $plan = collect($roles)->map(fn (string $role, int $index): array => [
            'family' => 'hybrid',
            'origin' => 'causal_repair',
            'target' => 'temporal_stability',
            'evolution_mode' => $role === 'frozen_control' ? 'frozen_control' : 'causal_repair_counterfactual',
            'niche' => [
                'slot' => $index + 1,
                'causal_repair_source_experiment_id' => 77,
                'promotion_evidence' => false,
            ],
        ])->all();
        $groupTargets = array_keys(LabPopulationService::POPULATION_GROUPS);
        foreach (range(0, 16) as $index) {
            $plan[] = [
                'family' => 'hybrid',
                'origin' => 'test_discovery',
                'target' => $groupTargets[$index % count($groupTargets)],
                'niche' => [],
            ];
        }

        $lab = new AiLaboratory([
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
        ]);
        $method = new \ReflectionMethod(LabPopulationService::class, 'fillNormalCouncilCore');
        $method->setAccessible(true);
        $filled = $method->invoke(app(LabPopulationService::class), $plan, $lab, 20);

        $this->assertCount(20, $filled);
        $this->assertSame(
            [77, 77, 77],
            collect($filled)->take(3)->pluck('niche.causal_repair_source_experiment_id')->all(),
        );
        $this->assertSame(
            ['causal_repair_counterfactual', 'causal_repair_counterfactual', 'frozen_control'],
            collect($filled)->take(3)->pluck('evolution_mode')->all(),
        );
    }

    public function test_dispatch_admission_consumes_the_same_exact_pair_protocol_as_the_constructor(): void
    {
        $command = app(DispatchLabGeneration::class);
        $method = new \ReflectionMethod($command, 'normalCausalAdmission');
        $method->setAccessible(true);
        $generation = (object) ['trigger_context' => [
            'research_allocation_budget' => ['mode' => 'normal_research'],
            'control_pairing_contract' => [
                'protocol' => ResearchAllocationPolicyService::CONTROL_PAIR_PROTOCOL,
                'allowed' => true,
                'missing_execution_lanes' => [],
                'missing_candidate_pairs' => [],
            ],
            'normal_structural_research_expected' => false,
        ]];

        $this->assertSame(['allowed' => true, 'reasons' => []], $method->invoke($command, $generation));

        $generation->trigger_context['control_pairing_contract']['protocol'] = 'frozen_control_pair_v1';
        $legacy = $method->invoke($command, $generation);
        $this->assertFalse($legacy['allowed']);
        $this->assertContains('NORMAL_CONTROL_PAIR_PROTOCOL_MISSING', $legacy['reasons']);
    }
}

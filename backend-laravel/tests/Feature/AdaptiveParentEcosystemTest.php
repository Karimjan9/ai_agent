<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabParentSelectionDecision;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\AdaptiveParentFrontierService;
use App\Services\EvolutionArchiveService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdaptiveParentEcosystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_robust_lane_can_use_multiple_diverse_parents_while_causal_lane_stays_single_parent(): void
    {
        $parents = collect();
        for ($index = 1; $index <= 5; $index++) {
            $model = $this->makeModel('robust-parent-'.$index, $index);
            $this->makePerformance($model, 70 + $index);
            $parents->push($model);
        }

        $service = app(AdaptiveParentFrontierService::class);
        $robust = $service->select(
            $parents,
            'XAUUSD', 'H1', 'trend', 'robust_crossover', 'robustness',
            ['role' => 'general'], 1,
        );
        $causal = $service->select(
            $parents,
            'XAUUSD', 'H1', 'trend', 'gate_targeted', 'exit_topology',
            ['role' => 'general'], 1,
        );

        $this->assertGreaterThanOrEqual(2, count($robust['selected_parent_ids']));
        $this->assertLessThanOrEqual(5, count($robust['selected_parent_ids']));
        $this->assertCount(1, $causal['selected_parent_ids']);
        $this->assertFalse($robust['contract']['promotion_evidence']);
        $this->assertSame('robust_capability_crossover', $robust['contract']['mode']);
        $this->assertArrayHasKey('parameter_sources', $robust['capability_genome']);
    }

    public function test_architecture_lane_uses_dynamic_capability_parents_with_gene_hashes(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'XAUUSD Architecture Lab', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true,
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 2, 'trigger_type' => 'test',
            'population_size' => 5, 'status' => 'draft', 'trigger_context' => [
                'adaptive_evolution_policy' => [
                    'observed_generations' => [1],
                    'exploration_ratio' => .80,
                    'diversity_collapse' => true,
                    'parent_concentration' => .75,
                    'lineage_cap' => .50,
                ],
            ],
        ]);
        $parents = collect();
        for ($index = 1; $index <= 5; $index++) {
            $parent = $this->makeModel('architecture-parent-'.$index, $index);
            $this->makePerformance($parent, 80 + $index);
            $parents->push($parent);
        }

        $selection = app(AdaptiveParentFrontierService::class)->select(
            $parents,
            'XAUUSD', 'H1', 'trend', 'architecture', 'architecture',
            ['role' => 'general'], 1, $generation,
        );

        $this->assertSame('architecture_discovery', $selection['contract']['mode']);
        $this->assertGreaterThanOrEqual(2, $selection['contract']['dynamic_k']);
        $this->assertTrue(collect($selection['capability_genome']['parameter_sources'])
            ->every(fn (array $source): bool => filled($source['source_parent_id'])
                && filled($source['source_module'])
                && filled($source['parameter_hash'])));
    }

    public function test_archive_preserves_failure_evidence_but_never_reintroduces_failure_as_parent(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'XAUUSD Lab', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true,
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 3, 'status' => 'completed', 'trigger_context' => [],
        ]);
        $good = $this->makeModel('archive-good', 1);
        $young = $this->makeModel('archive-young', 2);
        $failed = $this->makeModel('archive-failed', 3);
        $this->makePerformance($good, 82);
        $failedAgent = LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $failed->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'gate_targeted', 'lifecycle_status' => 'rejected',
            'decision_reason' => 'failed forward gate',
        ]);

        app(EvolutionArchiveService::class)->sync(
            $generation,
            collect([$good, $young]),
            collect([$good, $young]),
            'XAUUSD', 'H1', 'trend', 'curiosity_probe', 'unknown_state_curiosity',
            ['role' => 'general'],
        );

        $this->assertDatabaseHas('lab_evolution_archive_entries', [
            'model_version_id' => $good->id, 'archive_type' => 'convergence',
        ]);
        $this->assertDatabaseHas('lab_evolution_archive_entries', [
            'model_version_id' => $young->id, 'archive_type' => 'young',
        ]);
        $this->assertDatabaseHas('lab_evolution_archive_entries', [
            'model_version_id' => $failed->id, 'archive_type' => 'failure',
        ]);

        $frontier = app(EvolutionArchiveService::class)->augmentFrontier(
            collect(), 'XAUUSD', 'H1', 'trend', 'curiosity_probe', 'unknown_state_curiosity',
            ['role' => 'general'],
        );

        $this->assertNotContains($failed->id, $frontier->pluck('id')->all());
        $this->assertNotNull($failedAgent->fresh());
    }

    public function test_runtime_policy_waits_on_unknown_or_disagreeing_regimes(): void
    {
        $policy = app(\App\Services\EvolutionGovernorService::class)
            ->runtimePolicy('regime_ensemble', [11, 12, 13]);

        $this->assertSame('WAIT', $policy['unknown_regime_action']);
        $this->assertSame('WAIT', $policy['specialist_disagreement_action']);
        $this->assertTrue($policy['paper_and_holdout_required']);
        $this->assertFalse($policy['promotion_evidence']);
    }

    public function test_population_constructor_persists_dynamic_parent_decision_and_graph(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'XAUUSD Lab', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true,
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 1, 'status' => 'draft', 'trigger_context' => [
                'adaptive_evolution_policy' => app(\App\Services\EvolutionGovernorService::class)
                    ->scopeSnapshot('XAUUSD', 'H1'),
            ],
        ]);
        $parents = collect();
        for ($index = 1; $index <= 5; $index++) {
            $parent = $this->makeModel('constructor-parent-'.$index, $index);
            $this->makePerformance($parent, 75 + $index);
            $parents->push($parent);
        }

        $method = new \ReflectionMethod(\App\Services\LabPopulationService::class, 'createAgent');
        $method->setAccessible(true);
        $created = $method->invoke(
            app(\App\Services\LabPopulationService::class),
            $generation, 'trend', 'robust_crossover', 1, 'robustness', null, null,
        );
        $this->assertTrue($created);

        $agent = LabAgent::query()->latest('id')->firstOrFail();
        $decision = LabParentSelectionDecision::query()->where('lab_agent_id', $agent->id)->firstOrFail();
        $this->assertSame('robust_capability_crossover', $decision->mode);
        $this->assertGreaterThanOrEqual(2, $decision->selected_count);
        $this->assertFalse($decision->promotion_evidence);
        $this->assertGreaterThanOrEqual(2, $agent->parentLinks()->count());
        $this->assertSame(
            $decision->selected_parent_model_version_ids,
            (array) data_get($agent->modelVersion->metadata, 'adaptive_parent_ecosystem.selected_parent_model_version_ids'),
        );
    }

    public function test_governor_expands_later_generation_budget_without_erasing_causal_floor(): void
    {
        $plan = collect(range(1, 20))->map(fn (int $slot): array => [
            'origin' => 'g98_council',
            'target' => 'monthly_survival',
            'niche' => ['role' => 'general'],
            'slot' => $slot,
        ])->all();

        $snapshot = [
            'lookback_generations' => 2,
            'observed_generations' => [1, 2],
            'exploration_ratio' => .75,
            'diversity_collapse' => true,
            'parent_concentration' => .80,
            'stagnation_generations' => 3,
            'market_drift' => ['status' => 'recheck_required'],
        ];
        $adapted = app(\App\Services\EvolutionGovernorService::class)->adaptPlan($plan, $snapshot);

        $this->assertNotSame($plan, $adapted);
        $this->assertSame(
            array_column(array_slice($plan, 0, 8), 'origin'),
            array_column(array_slice($adapted, 0, 8), 'origin'),
        );
        $this->assertContains('robust_crossover', array_column($adapted, 'origin'));
        $this->assertContains('architecture', array_column($adapted, 'origin'));
        $this->assertTrue((bool) data_get($adapted[15], 'niche.research_only_until_independent_replay'));
        $this->assertFalse((bool) data_get($adapted[15], 'adaptive_governor.promotion_evidence'));
    }

    private function makeModel(string $name, int $variant): ModelVersion
    {
        $schema = app(StrategyParameterSchemaService::class);
        $parameters = $schema->defaults('trend');
        $parameters['ema_fast'] = 20 + $variant;
        $groups = app(StrategySemanticGroupService::class);

        return ModelVersion::create([
            'name' => $name,
            'strategy' => 'xauusd_'.$name,
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'best_score' => 60 + $variant,
            'parameters' => $parameters,
            'metadata' => [
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1',
                'strategy_architecture' => 'trend_pullback',
                'semantic_group' => $groups->descriptor('XAUUSD', 'H1', 'trend', ['role' => 'general']),
            ],
            'evidence_status' => 'valid',
        ]);
    }

    private function makePerformance(ModelVersion $model, float $forward): void
    {
        ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'status' => 'challenger', 'evidence_status' => 'valid', 'forward_score' => $forward,
            'sample_count' => 40, 'rolling_windows_count' => 4, 'rolling_forward_wins' => 4,
            'metrics' => [
                'profit_factor' => 1.5, 'max_drawdown_percent' => 8, 'is_overfit' => false,
                'monte_carlo' => ['risk_of_ruin_percent' => 0],
                'statistical_evidence' => ['edge_quality' => [
                    'bootstrap_pf' => ['status' => 'assessed', 'pf_5_percentile_lower_bound' => 1.1],
                    'worst_regime_sampled' => true, 'worst_regime_pf' => 1.1,
                ]],
                'behavioral_diversity' => ['status' => 'distinct'],
            ],
        ]);
    }
}

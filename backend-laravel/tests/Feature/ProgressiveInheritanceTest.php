<?php

namespace Tests\Feature;

use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Services\LabPopulationService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressiveInheritanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_frontier_prefers_progress_in_the_active_repair_lane(): void
    {
        $schema = app(StrategyParameterSchemaService::class)->defaults('differential_router');
        $rawWinner = ModelVersion::create([
            'name' => 'raw-forward-winner', 'strategy' => 'xauusd_raw_forward_winner', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => $schema,
            'metadata' => [], 'evidence_status' => 'valid',
        ]);
        $stressFocused = ModelVersion::create([
            'name' => 'stress-focused-frontier', 'strategy' => 'xauusd_stress_focused_frontier', 'version' => 'v1',
            'generation' => 2, 'status' => 'testing', 'parameters' => $schema,
            'metadata' => [], 'evidence_status' => 'valid',
        ]);

        $makePerformance = function (ModelVersion $model, float $forward, float $profitFactor, float $drawdown, float $stress): void {
            ModelMarketPerformance::create([
                'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
                'status' => 'challenger', 'evidence_status' => 'valid', 'forward_score' => $forward,
                'sample_count' => 40, 'rolling_windows_count' => 4, 'rolling_forward_wins' => 4,
                'metrics' => [
                    'profit_factor' => $profitFactor,
                    'max_drawdown_percent' => $drawdown,
                    'is_overfit' => false,
                    'monte_carlo' => ['risk_of_ruin_percent' => 0],
                    'pf_attribution' => ['stress_cost' => ['profit_factor' => $stress]],
                    'statistical_evidence' => ['edge_quality' => [
                        'bootstrap_pf' => ['status' => 'assessed', 'pf_5_percentile_lower_bound' => 1.1],
                        'worst_regime_sampled' => true, 'worst_regime_pf' => 1.1,
                    ]],
                    'behavioral_diversity' => ['status' => 'distinct'],
                ],
            ]);
        };

        // The first model has a marginally better headline score, but its
        // stress-cost lane is materially weaker. The active stress repair must
        // continue from the second model's closer frontier.
        $makePerformance($rawWinner, 79, 1.40, 9, .60);
        $makePerformance($stressFocused, 80, 1.30, 10, 1.30);

        $method = new \ReflectionMethod(LabPopulationService::class, 'qualityParents');
        $method->setAccessible(true);
        $parents = $method->invoke(
            app(LabPopulationService::class),
            'XAUUSD', 'H1', 'differential_router', 'stress_cost', ['regime' => 'trend_up'],
        );

        $this->assertSame($stressFocused->id, $parents->first()->id);
        $this->assertSame($rawWinner->id, $parents->get(1)->id);
    }

    public function test_child_carries_parent_parameters_lineage_and_confirmed_traits(): void
    {
        $service = app(LabPopulationService::class);
        $seedGeneration = $service->build('XAUUSD', 'progressive_inheritance_seed', true);
        $seedGeneration->update(['status' => 'completed']);

        $schema = app(StrategyParameterSchemaService::class)->defaults('differential_router');
        $schema['trend_ema_period'] = 31;
        $groups = app(\App\Services\StrategySemanticGroupService::class);
        $parent = ModelVersion::create([
            'name' => 'progressive-parent', 'strategy' => 'xauusd_progressive_parent', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => $schema,
            'metadata' => [
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1',
                'strategy_architecture' => 'frozen_parent_differential_router',
                'semantic_group' => $groups->descriptor('XAUUSD', 'H1', 'differential_router', [
                    'role' => 'general',
                ]),
            ],
            'evidence_status' => 'valid',
        ]);
        $parentAgent = LabAgent::create([
            'lab_generation_id' => $seedGeneration->id,
            'model_version_id' => $parent->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'origin' => 'full_validation', 'lifecycle_status' => 'challenger',
            'parameter_diff' => ['trend_ema_period' => ['old' => 20, 'new' => 31]],
        ]);
        MutationMemory::create([
            'lab_agent_id' => $parentAgent->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'differential_router', 'parameter_key' => 'trend_ema_period',
            'old_value' => ['value' => 20], 'new_value' => ['value' => 31],
            'forward_delta' => 8, 'market_regime' => 'trend_up', 'outcome' => 'beneficial',
            'confidence' => 90, 'decision' => 'confirmed beneficial trait',
            'independent_confirmation_count' => 2, 'non_target_regression_status' => 'passed',
        ]);
        ModelMarketPerformance::create([
            'model_version_id' => $parent->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'status' => 'challenger', 'evidence_status' => 'valid', 'forward_score' => 80,
            'sample_count' => 40, 'rolling_windows_count' => 4, 'rolling_forward_wins' => 4,
            'metrics' => [
                'profit_factor' => 1.5, 'max_drawdown_percent' => 8, 'is_overfit' => false,
                'monte_carlo' => ['risk_of_ruin_percent' => 0],
                'pf_attribution' => ['stress_cost' => ['profit_factor' => 1.2]],
                'statistical_evidence' => ['edge_quality' => [
                    'bootstrap_pf' => ['status' => 'assessed', 'pf_5_percentile_lower_bound' => 1.1],
                    'worst_regime_sampled' => true, 'worst_regime_pf' => 1.1,
                ]],
                'behavioral_diversity' => ['status' => 'distinct'],
            ],
        ]);

        $generation = $service->build('XAUUSD', 'progressive_inheritance_child', true);
        $child = $generation->agents
            ->first(fn (LabAgent $agent): bool => $agent->strategy_family === 'differential_router'
                && $agent->parent_a_model_version_id === $parent->id);

        $this->assertNotNull($child);
        $contract = (array) data_get($child->modelVersion->metadata, 'progressive_inheritance');
        $this->assertSame('progressive_frontier_inheritance_v1', $contract['protocol']);
        $this->assertSame($parent->id, $contract['parent_model_version_id']);
        $this->assertSame($parent->id, $contract['root_model_version_id']);
        $this->assertSame(1, $contract['lineage_depth']);
        $this->assertTrue(collect((array) $contract['confirmed_beneficial_traits'])
            ->contains(fn (array $trait): bool => data_get($trait, 'parameter_key') === 'trend_ema_period'));
        $this->assertGreaterThan(0, $contract['inherited_parameter_count']);

        $preservedKeys = array_values(array_diff(
            (array) $contract['inherited_parameter_keys'],
            (array) $contract['changed_parameter_keys'],
        ));
        $this->assertNotEmpty($preservedKeys);
        $preservedKey = $preservedKeys[0];
        $this->assertSame($parent->parameters[$preservedKey], $child->modelVersion->parameters[$preservedKey]);
    }

    public function test_parent_frontier_rejects_a_foreign_semantic_council_role(): void
    {
        $schema = app(StrategyParameterSchemaService::class)->defaults('differential_router');
        $groups = app(\App\Services\StrategySemanticGroupService::class);
        $wrong = ModelVersion::create([
            'name' => 'trend-down-parent', 'strategy' => 'xauusd_trend_down_parent', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => $schema,
            'metadata' => [
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1',
                'strategy_architecture' => 'frozen_parent_differential_router',
                'semantic_group' => $groups->descriptor('XAUUSD', 'H1', 'differential_router', [
                    'role' => 'trend_down_specialist', 'regime' => 'trend_down',
                    'volatility' => 'normal_volatility',
                ]),
            ],
            'evidence_status' => 'valid',
        ]);
        $right = ModelVersion::create([
            'name' => 'trend-up-parent', 'strategy' => 'xauusd_trend_up_parent', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => $schema,
            'metadata' => [
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1',
                'strategy_architecture' => 'frozen_parent_differential_router',
                'semantic_group' => $groups->descriptor('XAUUSD', 'H1', 'differential_router', [
                    'role' => 'trend_up_specialist', 'regime' => 'trend_up',
                    'volatility' => 'high_volatility',
                ]),
            ],
            'evidence_status' => 'valid',
        ]);
        foreach ([$wrong, $right] as $model) {
            ModelMarketPerformance::create([
                'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
                'status' => 'challenger', 'evidence_status' => 'valid', 'forward_score' => 70,
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

        $method = new \ReflectionMethod(LabPopulationService::class, 'qualityParents');
        $method->setAccessible(true);
        $parents = $method->invoke(
            app(LabPopulationService::class),
            'XAUUSD', 'H1', 'differential_router', 'opportunity_recall', [
                'role' => 'trend_up_specialist', 'regime' => 'trend_up', 'volatility' => 'high_volatility',
            ],
        );

        $this->assertSame([$right->id], $parents->pluck('id')->all());
    }
}

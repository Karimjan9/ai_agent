<?php

namespace Tests\Feature;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\StrategyGenome;
use App\Services\EvolutionGenomeService;
use App\Services\MarketChampionService;
use App\Services\StrategySemanticGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemanticGroupIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_compatibility_rejects_a_foreign_strategy_family(): void
    {
        $groups = app(StrategySemanticGroupService::class);
        $hybrid = ModelVersion::create([
            'name' => 'hybrid-diagnostic-source', 'strategy' => 'xauusd_hybrid_source',
            'version' => 'v1', 'generation' => 1, 'status' => 'testing',
            'parameters' => [], 'metadata' => [
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1',
                'semantic_group' => $groups->descriptor('XAUUSD', 'H1', 'hybrid', [
                    'role' => 'general', 'regime' => 'trend_down',
                ]),
            ], 'evidence_status' => 'valid',
        ]);

        $this->assertFalse($groups->parentCompatible($hybrid, 'differential_router', [
            'role' => 'differential_router', 'regime' => 'trend_down',
        ]));
    }

    public function test_group_champion_does_not_cross_a_council_role_boundary(): void
    {
        $groups = app(StrategySemanticGroupService::class);
        $trendUp = ModelVersion::create([
            'name' => 'breakout-trend-up-champion', 'strategy' => 'breakout_trend_up_champion',
            'version' => 'v1', 'generation' => 1, 'status' => 'active',
            'parameters' => [], 'metadata' => [
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1',
                'strategy_architecture' => 'breakout_retest',
                'semantic_group' => $groups->descriptor('XAUUSD', 'H1', 'breakout', [
                    'role' => 'trend_up_specialist', 'regime' => 'trend_up', 'volatility' => 'high_volatility',
                ], 'breakout_retest'),
            ], 'evidence_status' => 'valid',
        ]);
        $range = ModelVersion::create([
            'name' => 'breakout-range-candidate', 'strategy' => 'breakout_range_candidate',
            'version' => 'v1', 'generation' => 2, 'status' => 'testing',
            'parameters' => [], 'metadata' => [
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1',
                'strategy_architecture' => 'breakout_retest',
                'semantic_group' => $groups->descriptor('XAUUSD', 'H1', 'breakout', [
                    'role' => 'range_specialist', 'regime' => 'range', 'volatility' => 'low_volatility',
                ], 'breakout_retest'),
            ], 'evidence_status' => 'valid',
        ]);
        $championPerformance = ModelMarketPerformance::create([
            'model_version_id' => $trendUp->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'breakout',
            'status' => 'champion', 'evidence_status' => 'valid', 'forward_score' => 90,
            'metrics' => [],
        ]);

        $method = new \ReflectionMethod(MarketChampionService::class, 'groupChampion');
        $method->setAccessible(true);
        $service = app(MarketChampionService::class);

        $this->assertNull($method->invoke($service, 'XAUUSD', 'H1', 'breakout', $range));
        $this->assertSame(
            $championPerformance->id,
            $method->invoke($service, 'XAUUSD', 'H1', 'breakout', $trendUp)?->id,
        );
    }

    public function test_genome_crossover_rejects_a_foreign_role_inside_the_same_family(): void
    {
        $groups = app(StrategySemanticGroupService::class);
        $trendUp = ModelVersion::create([
            'name' => 'differential-trend-up-genome', 'strategy' => 'xauusd_differential_trend_up',
            'version' => 'v1', 'generation' => 1, 'status' => 'testing',
            'parameters' => [], 'metadata' => [
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1',
                'semantic_group' => $groups->descriptor('XAUUSD', 'H1', 'differential_router', [
                    'role' => 'trend_up_specialist', 'regime' => 'trend_up',
                ]),
            ], 'evidence_status' => 'valid',
        ]);
        $range = ModelVersion::create([
            'name' => 'differential-range-genome', 'strategy' => 'xauusd_differential_range',
            'version' => 'v1', 'generation' => 1, 'status' => 'testing',
            'parameters' => [], 'metadata' => [
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1',
                'semantic_group' => $groups->descriptor('XAUUSD', 'H1', 'differential_router', [
                    'role' => 'range_specialist', 'regime' => 'range',
                ]),
            ], 'evidence_status' => 'valid',
        ]);
        $left = StrategyGenome::create([
            'model_version_id' => $trendUp->id, 'strategy' => $trendUp->strategy,
            'family' => 'differential_router', 'version' => 'v1', 'generation' => 1,
            'genome_hash' => hash('sha256', 'semantic-left'), 'genes' => [],
            'fitness_score' => 90, 'status' => 'alive', 'born_at' => now(),
        ]);
        $right = StrategyGenome::create([
            'model_version_id' => $range->id, 'strategy' => $range->strategy,
            'family' => 'differential_router', 'version' => 'v1', 'generation' => 1,
            'genome_hash' => hash('sha256', 'semantic-right'), 'genes' => [],
            'fitness_score' => 80, 'status' => 'alive', 'born_at' => now(),
        ]);

        $method = new \ReflectionMethod(EvolutionGenomeService::class, 'sameSemanticGroup');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(app(EvolutionGenomeService::class), $left, $right));
    }
}

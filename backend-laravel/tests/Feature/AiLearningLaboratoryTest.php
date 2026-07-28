<?php

namespace Tests\Feature;

use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\LabPopulationService;
use App\Services\LabCandidateSelectionService;
use Illuminate\Support\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiLearningLaboratoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_pair_gets_a_bounded_owned_twenty_agent_population(): void
    {
        $service = app(LabPopulationService::class);
        $xau = $service->build('XAUUSD', 'new_data', true);
        $eur = $service->build('EURUSD', 'new_data', true);

        $this->assertCount(20, $xau->agents);
        $this->assertCount(20, $eur->agents);
        $this->assertSame(8, $xau->agents->where('origin', 'gate_targeted')->count());
        $this->assertSame(4, $xau->agents->where('origin', 'risk_exit')->count());
        $this->assertSame(3, $xau->agents->where('origin', 'architecture')->count());
        $this->assertSame(3, $xau->agents->where('origin', 'robust_crossover')->count());
        $this->assertSame(2, $xau->agents->where('origin', 'random_explorer')->count());
        $this->assertTrue($xau->agents->every(fn (LabAgent $agent) => $agent->lifecycle_status === 'draft'));
        $this->assertContains(data_get($xau->agents->first()->modelVersion->metadata, 'generation_target'), ['trade_frequency', 'profit_factor', 'drawdown_risk', 'rolling_regime', 'architecture']);
        $this->assertTrue($xau->agents->every(fn (LabAgent $agent) => str_starts_with($agent->modelVersion->strategy, 'xauusd_')));
        $this->assertTrue($eur->agents->every(fn (LabAgent $agent) => str_starts_with($agent->modelVersion->strategy, 'eurusd_')));
        $this->assertEqualsCanonicalizing(['breakout', 'hybrid', 'regime_ensemble', 'trend', 'volatility'], $xau->agents->pluck('strategy_family')->unique()->all());
        $this->assertEqualsCanonicalizing(['hybrid', 'mean_reversion', 'regime_ensemble', 'session', 'trend'], $eur->agents->pluck('strategy_family')->unique()->all());
    }

    public function test_generation_is_not_repeated_without_enough_new_data(): void
    {
        $service = app(LabPopulationService::class);
        $this->assertNotNull($service->build('GBPUSD', 'new_data', false));
        $this->assertNull($service->build('GBPUSD', 'new_data', false));
        $this->assertDatabaseCount('lab_generations', 1);
    }

    public function test_single_hour_drift_does_not_create_a_generation_storm(): void
    {
        $service = app(LabPopulationService::class);
        $this->assertNotNull($service->build('GBPUSD', 'new_data', true));

        $this->assertNull($service->build('GBPUSD', 'market_drift'));
        $this->assertDatabaseCount('lab_generations', 1);
    }

    public function test_pair_laboratory_dashboard_renders_learning_evidence(): void
    {
        app(LabPopulationService::class)->build('XAUUSD', 'market_drift', true);
        $this->get(route('ai-laboratory.show', 'XAUUSD'))
            ->assertOk()->assertSee('XAUUSD Lab')->assertSee('Generation population')
            ->assertSee('Generation bo‘yicha forward performance')->assertSee('Full replay funnel')
            ->assertSee('Candidate gate decision ledger')->assertSee('20/20');
    }

    public function test_low_quality_challenger_is_never_used_as_a_parent(): void
    {
        $weak = ModelVersion::create([
            'name' => 'weak-trend', 'strategy' => 'xauusd_trend_g1_a01', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => app(\App\Services\StrategyParameterSchemaService::class)->defaults('trend'),
            'metadata' => [], 'evidence_status' => 'valid',
        ]);
        ModelMarketPerformance::create([
            'model_version_id' => $weak->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'status' => 'challenger', 'evidence_status' => 'valid', 'forward_score' => 99, 'sample_count' => 80,
            'rolling_windows_count' => 3, 'rolling_forward_wins' => 3,
            'metrics' => ['profit_factor' => 1.1, 'max_drawdown_percent' => 8, 'is_overfit' => false, 'monte_carlo' => ['risk_of_ruin_percent' => 5]],
        ]);

        $generation = app(LabPopulationService::class)->build('XAUUSD', 'market_drift', true);
        $trendElite = $generation->agents->first(fn (LabAgent $agent) => $agent->origin === 'gate_targeted' && $agent->strategy_family === 'trend');

        $this->assertNull($trendElite->parent_a_model_version_id);
    }

    public function test_laboratory_dashboard_explains_a_candidate_forward_gate_failure(): void
    {
        $model = ModelVersion::create([
            'name' => 'weak-breakout', 'strategy' => 'xauusd_breakout_g1_a01', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => app(\App\Services\StrategyParameterSchemaService::class)->defaults('breakout'),
            'metadata' => [], 'evidence_status' => 'valid',
        ]);
        ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'breakout',
            'status' => 'challenger', 'evidence_status' => 'valid', 'forward_score' => 50, 'sample_count' => 80,
            'rolling_windows_count' => 3, 'rolling_forward_wins' => 3,
            'metrics' => ['profit_factor' => 1.1, 'max_drawdown_percent' => 8, 'is_overfit' => false, 'monte_carlo' => ['risk_of_ruin_percent' => 5]],
        ]);

        $this->get(route('ai-laboratory.show', 'XAUUSD'))
            ->assertOk()->assertSee('Forward-gate diagnostics')->assertSee('PF >= 1.30');
    }

    public function test_strategy_architecture_is_a_dynamic_gene_not_just_a_parameter_label(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'architecture_evolution', true);
        $trend = $generation->agents->where('strategy_family', 'trend')->map(
            fn (LabAgent $agent) => data_get($agent->modelVersion->metadata, 'strategy_architecture')
        )->unique()->values();

        $this->assertContains('trend_pullback', $trend);
        $this->assertContains('trend_breakout_retest', $trend);
        $this->assertTrue($generation->agents->every(fn (LabAgent $agent) => filled(data_get($agent->modelVersion->metadata, 'base_strategy'))));
    }

    public function test_dynamic_frontier_does_not_spend_full_replay_on_two_trade_luck(): void
    {
        $agents = collect([
            (object) ['id' => 1, 'sample_count' => 2, 'profit_factor' => 17.8, 'forward_score' => 35, 'max_drawdown' => .1, 'risk_of_ruin' => 0, 'modelVersion' => null],
            (object) ['id' => 2, 'sample_count' => 12, 'profit_factor' => 1.2, 'forward_score' => 12, 'max_drawdown' => 2, 'risk_of_ruin' => 0, 'modelVersion' => null],
        ]);

        $selected = app(LabCandidateSelectionService::class)->select($agents);

        $this->assertSame([2], $selected->pluck('id')->all());
    }
}

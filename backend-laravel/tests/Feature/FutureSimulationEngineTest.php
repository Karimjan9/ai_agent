<?php

namespace Tests\Feature;

use App\Models\FutureDiscovery;
use App\Models\FutureScenario;
use App\Models\FutureSimulationRun;
use App\Models\FutureStressTest;
use App\Models\FutureTimelineForecast;
use App\Models\KnowledgeClaim;
use App\Models\MarketGenome;
use App\Models\MarketSpecies;
use App\Models\MarketStateSnapshot;
use App\Models\StrategyScore;
use App\Models\StrategySurvivalForecast;
use App\Models\TrainingSession;
use App\Services\FutureSimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FutureSimulationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_future_simulation_creates_scenarios_timeline_survival_stress_and_discoveries(): void
    {
        $this->createFutureEvidence();

        $run = app(FutureSimulationService::class)->simulate('XAUUSD', 'H1', 1000);

        $this->assertNotNull($run);
        $this->assertDatabaseHas('future_simulation_runs', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'scenario_count' => 1000,
        ]);
        $this->assertGreaterThan(0, FutureScenario::count());
        $this->assertGreaterThan(0, FutureTimelineForecast::count());
        $this->assertGreaterThan(0, StrategySurvivalForecast::count());
        $this->assertGreaterThan(0, FutureStressTest::count());
        $this->assertGreaterThan(0, FutureDiscovery::count());

        $this->assertDatabaseHas('future_scenarios', [
            'future_simulation_run_id' => $run->id,
            'scenario_key' => 'bull_continuation',
        ]);
    }

    public function test_future_intelligence_dashboard_and_manual_simulation_work(): void
    {
        $this->createFutureEvidence();

        $this->post(route('future-intelligence.simulate'), [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'scenario_count' => 1000,
        ])->assertRedirect(route('future-intelligence.index'));

        $this->get(route('future-intelligence.index'))
            ->assertOk()
            ->assertSee('Future Intelligence')
            ->assertSee('Future Map')
            ->assertSee('Probability Tree')
            ->assertSee('Survival Forecast')
            ->assertSee('Future Stress Tests');
    }

    public function test_future_simulation_command_runs(): void
    {
        $this->createFutureEvidence();

        Artisan::call('future:simulate', [
            '--symbol' => 'XAUUSD',
            '--timeframe' => 'H1',
            '--scenarios' => 1000,
        ]);

        $this->assertGreaterThan(0, FutureSimulationRun::count());
    }

    private function createFutureEvidence(): void
    {
        $species = MarketSpecies::create([
            'code' => 'SPC_FUT41',
            'name' => 'Fear Expansion',
            'dominant_state' => 'panic',
            'description' => 'Future test species.',
            'danger_score' => 76,
            'opportunity_score' => 58,
            'signature' => ['market_state' => 'panic'],
        ]);

        $snapshot = MarketStateSnapshot::create([
            'market_species_id' => $species->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'time' => '2026-01-01 10:00:00',
            'market_state' => 'panic',
            'liquidity_state' => 'low_proxy',
            'momentum_state' => 'strong',
            'structure_state' => 'breakout',
            'confidence_score' => 82,
            'trend_score' => 72,
            'panic_score' => 81,
            'compression_score' => 44,
            'expansion_score' => 78,
            'momentum_score' => 76,
            'liquidity_proxy_score' => 32,
            'features' => ['trend_score' => 72, 'liquidity_proxy_score' => 32],
            'explanation' => 'Fear Expansion test snapshot.',
        ]);

        MarketGenome::create([
            'market_state_snapshot_id' => $snapshot->id,
            'market_species_id' => $species->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'time' => '2026-01-01 10:00:00',
            'genome_hash' => 'future-test-genome',
            'vector' => [
                'trend' => 72,
                'panic' => 81,
                'compression' => 44,
                'momentum' => 76,
                'liquidity_proxy' => 32,
                'expansion' => 78,
            ],
            'trend' => 72,
            'panic' => 81,
            'compression' => 44,
            'momentum' => 76,
            'liquidity_proxy' => 32,
        ]);

        $session = TrainingSession::create([
            'title' => 'Future Simulation Session',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'agents_count' => 1,
            'best_strategy' => 'ema_rsi_v12',
            'best_score' => 84,
            'worst_strategy' => 'breakout_v3',
            'worst_score' => 38,
            'total_trades' => 20,
            'average_winrate' => 68,
            'average_profit' => 4.8,
            'average_drawdown' => 5.4,
            'average_profit_factor' => 1.8,
            'average_stability_score' => 78,
            'ai_conclusion' => 'Future simulation evidence.',
            'next_training_plan' => 'Simulate futures.',
            'raw_leaderboard' => [],
            'status' => 'completed',
        ]);

        StrategyScore::create([
            'training_session_id' => $session->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'ema_rsi_v12',
            'parameters' => ['ema_fast' => 45, 'rsi_buy_min' => 55],
            'score' => 84,
            'robustness_score' => 81,
            'stability_score' => 79,
            'total_trades' => 20,
            'wins' => 14,
            'losses' => 6,
            'winrate' => 70,
            'net_profit_percent' => 4.8,
            'max_drawdown_percent' => 5.4,
            'profit_factor' => 1.8,
            'raw_result' => [],
        ]);

        KnowledgeClaim::create([
            'title' => 'Fear Expansion increases trend failure',
            'claim' => 'Fear Expansion failure pressure increases probability of trend failure.',
            'claim_type' => 'failure_cause',
            'confidence_score' => 78,
            'evidence_count' => 12,
            'status' => 'provisional',
            'scope' => ['market_species' => 'Fear Expansion'],
            'metadata' => [],
        ]);
    }
}

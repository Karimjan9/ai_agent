<?php

namespace Tests\Feature;

use App\Models\AgentBelief;
use App\Models\AgentHypothesis;
use App\Models\CounterfactualRun;
use App\Models\KnowledgeFact;
use App\Models\ModelVersion;
use App\Models\ScientistJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiTradingScientistTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_all_creates_ai_scientist_artifacts(): void
    {
        ModelVersion::create([
            'name' => 'ema_rsi_v1',
            'strategy' => 'ema_rsi_v1',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => ['ema_fast' => 50, 'ema_slow' => 200],
            'metadata' => [],
        ]);

        Http::fake([
            'http://127.0.0.1:9000/api/backtest/run-all' => Http::response([
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'leaderboard' => [
                    [
                        'strategy' => 'ema_rsi_v1',
                        'parameters' => ['ema_fast' => 50, 'ema_slow' => 200],
                        'score' => 78,
                        'train_score' => 80,
                        'validation_score' => 79,
                        'forward_score' => 78,
                        'robustness_score' => 94,
                        'is_overfit' => false,
                        'result' => [
                            'total_trades' => 12,
                            'wins' => 7,
                            'losses' => 5,
                            'winrate' => 58.3,
                            'net_profit_percent' => 7.8,
                            'max_drawdown_percent' => 5.4,
                            'profit_factor' => 1.45,
                            'average_win_percent' => 0.9,
                            'average_loss_percent' => 0.45,
                            'risk_reward_ratio' => 2.0,
                            'max_consecutive_losses' => 2,
                            'stability_score' => 84,
                            'equity_curve' => [10000, 10090, 10040, 10780],
                            'regime_performance' => [
                                'trend_up' => [
                                    'trades' => 8,
                                    'wins' => 6,
                                    'losses' => 2,
                                    'winrate' => 75,
                                    'profit_percent' => 6.4,
                                ],
                            ],
                            'volatility_performance' => [
                                'normal_volatility' => [
                                    'trades' => 8,
                                    'wins' => 6,
                                    'losses' => 2,
                                    'winrate' => 75,
                                    'profit_percent' => 6.4,
                                ],
                            ],
                            'monte_carlo' => [
                                'risk_of_ruin_percent' => 3.0,
                                'worst_drawdown_percent' => 13.0,
                                'worst_profit_percent' => -4.2,
                                'avg_profit_percent' => 7.2,
                                'best_profit_percent' => 19.5,
                                'avg_drawdown_percent' => 6.5,
                                'worst_equity_curve' => [10000, 9700, 10400],
                                'best_equity_curve' => [10000, 10800, 11950],
                            ],
                            'strategy_dna' => [
                                'aggression_score' => 52,
                                'trend_dependency' => 80,
                                'range_dependency' => 30,
                                'volatility_sensitivity' => 42,
                                'adaptability_score' => 76,
                                'recovery_score' => 79,
                                'survival_score' => 87,
                                'dna_summary' => 'EMA RSI is a balanced trend confirmation strategy.',
                            ],
                            'trades' => [
                                [
                                    'direction' => 'long',
                                    'entry_time' => '2026-01-01 01:00:00',
                                    'exit_time' => '2026-01-01 08:00:00',
                                    'entry_price' => 2000,
                                    'exit_price' => 2020,
                                    'result' => 'WIN',
                                    'profit_percent' => 1.0,
                                    'balance' => 10100,
                                    'market_regime' => 'trend_up',
                                    'volatility_regime' => 'normal_volatility',
                                ],
                                [
                                    'direction' => 'long',
                                    'entry_time' => '2026-01-02 01:00:00',
                                    'exit_time' => '2026-01-02 08:00:00',
                                    'entry_price' => 2020,
                                    'exit_price' => 2010,
                                    'result' => 'LOSS',
                                    'profit_percent' => -0.5,
                                    'balance' => 10050,
                                    'market_regime' => 'trend_up',
                                    'volatility_regime' => 'normal_volatility',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->post('/strategy-lab/run-all', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
        ])->assertRedirect(route('training-sessions.index'));

        $this->assertDatabaseHas('agent_hypotheses', [
            'strategy' => 'ema_rsi_v1',
            'decision' => 'BUY',
            'status' => 'confirmed',
            'market_regime' => 'trend_up',
        ]);

        $this->assertDatabaseHas('agent_hypotheses', [
            'strategy' => 'ema_rsi_v1',
            'status' => 'failed',
        ]);

        $this->assertDatabaseHas('agent_beliefs', [
            'strategy' => 'ema_rsi_v1',
            'belief_key' => 'rsi_confirmation',
        ]);

        $this->assertDatabaseHas('scientist_journals', [
            'title' => 'Scientist Journal #1',
        ]);

        $this->assertDatabaseHas('knowledge_facts', [
            'title' => 'EMA_RSI_V1 performs well during trend_up',
            'status' => 'validated',
        ]);

        $this->assertDatabaseHas('counterfactual_runs', [
            'scenario_name' => 'without_rsi_filter',
            'verdict' => 'improved',
        ]);

        $this->assertSame(2, AgentHypothesis::count());
        $this->assertGreaterThanOrEqual(3, AgentBelief::count());
        $this->assertSame(1, ScientistJournal::count());
        $this->assertSame(1, KnowledgeFact::count());
        $this->assertGreaterThanOrEqual(3, CounterfactualRun::count());
    }

    public function test_ai_scientist_dashboard_renders_scientific_memory(): void
    {
        AgentBelief::create([
            'strategy' => 'ema_rsi_v1',
            'belief_key' => 'trend_following',
            'belief_label' => 'Trend following edge',
            'score' => 82,
            'sample_size' => 20,
        ]);

        $response = $this->get('/ai-scientist');

        $response->assertOk()
            ->assertSee('AI Scientist')
            ->assertSee('Hypotheses')
            ->assertSee('Beliefs')
            ->assertSee('Scientist Journals')
            ->assertSee('Knowledge Base')
            ->assertSee('Counterfactuals')
            ->assertSee('Trend following edge');
    }
}

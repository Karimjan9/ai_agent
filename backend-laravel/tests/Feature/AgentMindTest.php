<?php

namespace Tests\Feature;

use App\Models\AgentMemory;
use App\Models\AgentPsychologySnapshot;
use App\Models\AgentReputation;
use App\Models\AgentSelfReflection;
use App\Models\EvolutionTrigger;
use App\Models\InternalDebate;
use App\Models\ModelVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentMindTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_all_creates_agent_mind_artifacts_and_triggers(): void
    {
        ModelVersion::create([
            'name' => 'breakout_v1',
            'strategy' => 'breakout_v1',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => ['confirmation_candles' => 1],
            'metadata' => [],
        ]);

        Http::fake([
            'http://127.0.0.1:9000/api/backtest/run-all' => Http::response([
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'leaderboard' => [
                    [
                        'strategy' => 'breakout_v1',
                        'parameters' => ['confirmation_candles' => 1],
                        'score' => 25,
                        'train_score' => 90,
                        'validation_score' => 42,
                        'forward_score' => 22,
                        'robustness_score' => 20,
                        'is_overfit' => true,
                        'result' => [
                            'total_trades' => 40,
                            'wins' => 10,
                            'losses' => 30,
                            'winrate' => 25.0,
                            'net_profit_percent' => -12.0,
                            'max_drawdown_percent' => 30.0,
                            'profit_factor' => 0.5,
                            'average_win_percent' => 0.4,
                            'average_loss_percent' => 0.9,
                            'risk_reward_ratio' => 0.44,
                            'max_consecutive_losses' => 8,
                            'stability_score' => 20,
                            'equity_curve' => [10000, 9300, 8800],
                            'regime_performance' => [
                                'range' => [
                                    'trades' => 8,
                                    'wins' => 1,
                                    'losses' => 7,
                                    'winrate' => 12.5,
                                    'profit_percent' => -8.0,
                                ],
                            ],
                            'volatility_performance' => [
                                'high_volatility' => [
                                    'trades' => 8,
                                    'wins' => 1,
                                    'losses' => 7,
                                    'winrate' => 12.5,
                                    'profit_percent' => -8.0,
                                ],
                            ],
                            'monte_carlo' => [
                                'risk_of_ruin_percent' => 42.0,
                                'worst_drawdown_percent' => 50.0,
                                'worst_profit_percent' => -30.0,
                                'avg_profit_percent' => -8.0,
                                'best_profit_percent' => 4.0,
                                'avg_drawdown_percent' => 28.0,
                                'worst_equity_curve' => [10000, 7400],
                                'best_equity_curve' => [10000, 10400],
                            ],
                            'strategy_dna' => [
                                'aggression_score' => 88,
                                'trend_dependency' => 94,
                                'range_dependency' => 6,
                                'volatility_sensitivity' => 86,
                                'adaptability_score' => 20,
                                'recovery_score' => 28,
                                'survival_score' => 30,
                                'dna_summary' => 'Breakout is over-dependent on trend continuation.',
                            ],
                            'trades' => [
                                [
                                    'direction' => 'long',
                                    'entry_time' => '2026-01-01 01:00:00',
                                    'exit_time' => '2026-01-01 03:00:00',
                                    'entry_price' => 2000,
                                    'exit_price' => 1985,
                                    'result' => 'LOSS',
                                    'profit_percent' => -0.75,
                                    'balance' => 9925,
                                    'market_regime' => 'range',
                                    'volatility_regime' => 'high_volatility',
                                ],
                                [
                                    'direction' => 'long',
                                    'entry_time' => '2026-01-02 01:00:00',
                                    'exit_time' => '2026-01-02 03:00:00',
                                    'entry_price' => 1985,
                                    'exit_price' => 1960,
                                    'result' => 'LOSS',
                                    'profit_percent' => -1.26,
                                    'balance' => 9800,
                                    'market_regime' => 'range',
                                    'volatility_regime' => 'high_volatility',
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

        $this->assertDatabaseHas('agent_psychology_snapshots', [
            'strategy' => 'breakout_v1',
            'state' => 'adaptation_required',
        ]);

        $this->assertDatabaseHas('agent_self_reflections', [
            'strategy' => 'breakout_v1',
        ]);

        $this->assertDatabaseHas('agent_memories', [
            'strategy' => 'breakout_v1',
            'memory_type' => 'market_mismatch',
            'market_regime' => 'range',
        ]);

        $this->assertDatabaseHas('agent_reputations', [
            'strategy' => 'breakout_v1',
            'sessions_count' => 1,
        ]);

        $this->assertDatabaseHas('internal_debates', [
            'training_session_id' => 1,
            'final_decision' => 'NO',
        ]);

        $this->assertDatabaseHas('evolution_triggers', [
            'strategy' => 'breakout_v1',
            'trigger_type' => 'stress',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('evolution_triggers', [
            'strategy' => 'breakout_v1',
            'trigger_type' => 'adaptation_pressure',
            'status' => 'pending',
        ]);

        $snapshot = AgentPsychologySnapshot::first();
        $this->assertGreaterThan(80, (float) $snapshot->stress);
        $this->assertGreaterThan(85, (float) $snapshot->adaptation_pressure);
        $this->assertSame(1, AgentSelfReflection::count());
        // Decision-learning now adds separate entry/exit/architecture evidence
        // alongside AgentMind's own reflection memory.
        $this->assertGreaterThanOrEqual(1, AgentMemory::count());
        $this->assertSame(1, AgentReputation::count());
        $this->assertSame(1, InternalDebate::count());
        $this->assertSame(2, EvolutionTrigger::count());
    }

    public function test_agent_mind_dashboard_renders(): void
    {
        AgentPsychologySnapshot::create([
            'strategy' => 'ema_rsi_v1',
            'confidence' => 87,
            'stress' => 22,
            'trust' => 91,
            'adaptation_pressure' => 17,
            'stability' => 81,
            'learning_rate' => 0.12,
            'state' => 'stable',
            'metrics' => [],
        ]);

        $response = $this->get('/agent-mind');

        $response->assertOk()
            ->assertSee('Agent Mind')
            ->assertSee('Psychology')
            ->assertSee('Reputation')
            ->assertSee('Self Reflections')
            ->assertSee('Internal Debate')
            ->assertSee('EMA_RSI_V1');
    }
}

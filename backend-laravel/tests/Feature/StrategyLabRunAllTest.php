<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Models\StrategyScore;
use App\Models\TrainingSession;
use App\Models\EvolutionProposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StrategyLabRunAllTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_lab_page_shows_leaderboard(): void
    {
        StrategyScore::create([
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'macd_trend_v1',
            'score' => 84,
            'total_trades' => 185,
            'wins' => 113,
            'losses' => 72,
            'winrate' => 61.2,
            'net_profit_percent' => 22.4,
            'max_drawdown_percent' => 6.2,
            'profit_factor' => 1.74,
            'stability_score' => 90,
            'raw_result' => [],
        ]);

        $response = $this->get('/strategy-lab');

        $response->assertOk()
            ->assertSee('Strategy Lab')
            ->assertSee('Start New Training Session')
            ->assertSee('Top Strategy Scores')
            ->assertSee('topScoreChart')
            ->assertSee('topProfitDrawdownChart')
            ->assertSee('MACD_TREND_V1')
            ->assertSee('84');
    }

    public function test_run_all_posts_to_ai_service_and_stores_strategy_scores(): void
    {
        ModelVersion::create([
            'name' => 'macd_trend_v1',
            'strategy' => 'macd_trend_v1',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => ['ema_trend' => 100],
            'metadata' => [],
        ]);

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
                        'strategy' => 'macd_trend_v1',
                        'parameters' => ['ema_trend' => 100],
                        'score' => 84,
                        'result' => [
                            'total_trades' => 185,
                            'wins' => 113,
                            'losses' => 72,
                            'winrate' => 61.2,
                            'net_profit_percent' => 22.4,
                            'max_drawdown_percent' => 6.2,
                            'profit_factor' => 1.74,
                            'average_win_percent' => 1.1,
                            'average_loss_percent' => 0.5,
                            'risk_reward_ratio' => 2.2,
                            'max_consecutive_losses' => 3,
                            'stability_score' => 90,
                            'equity_curve' => [10000, 10100],
                            'regime_performance' => [
                                'trend_up' => [
                                    'trades' => 12,
                                    'wins' => 8,
                                    'losses' => 4,
                                    'winrate' => 66.67,
                                    'profit_percent' => 4.2,
                                ],
                            ],
                            'volatility_performance' => [
                                'high_volatility' => [
                                    'trades' => 12,
                                    'wins' => 8,
                                    'losses' => 4,
                                    'winrate' => 66.67,
                                    'profit_percent' => 4.2,
                                ],
                            ],
                        ],
                    ],
                    [
                        'strategy' => 'ema_rsi_v1',
                        'parameters' => ['ema_fast' => 50, 'ema_slow' => 200],
                        'score' => 76,
                        'result' => [
                            'total_trades' => 248,
                            'wins' => 140,
                            'losses' => 108,
                            'winrate' => 56.4,
                            'net_profit_percent' => 18.5,
                            'max_drawdown_percent' => 8.7,
                            'profit_factor' => 1.42,
                            'average_win_percent' => 0.9,
                            'average_loss_percent' => 0.6,
                            'risk_reward_ratio' => 1.5,
                            'max_consecutive_losses' => 4,
                            'stability_score' => 82,
                            'equity_curve' => [10000, 10080],
                            'regime_performance' => [
                                'range' => [
                                    'trades' => 14,
                                    'wins' => 7,
                                    'losses' => 7,
                                    'winrate' => 50.0,
                                    'profit_percent' => 1.2,
                                ],
                            ],
                            'volatility_performance' => [
                                'normal_volatility' => [
                                    'trades' => 14,
                                    'wins' => 7,
                                    'losses' => 7,
                                    'winrate' => 50.0,
                                    'profit_percent' => 1.2,
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->post('/strategy-lab/run-all', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
        ]);

        $response->assertRedirect(route('training-sessions.index'));

        $this->assertDatabaseHas('training_sessions', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'agents_count' => 2,
            'best_strategy' => 'macd_trend_v1',
            'best_score' => 84,
            'worst_strategy' => 'ema_rsi_v1',
            'worst_score' => 76,
            'total_trades' => 433,
            'average_drawdown' => 7.45,
            'average_profit_factor' => 1.58,
            'average_stability_score' => 86,
        ]);

        $this->assertDatabaseHas('strategy_scores', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'macd_trend_v1',
            'score' => 84,
            'total_trades' => 185,
        ]);

        $this->assertDatabaseHas('strategy_scores', [
            'strategy' => 'macd_trend_v1',
            'parameters' => json_encode(['ema_trend' => 100]),
            'profit_factor' => 1.74,
            'average_win_percent' => 1.1,
            'average_loss_percent' => 0.5,
            'risk_reward_ratio' => 2.2,
            'max_consecutive_losses' => 3,
            'stability_score' => 90,
        ]);

        $macdScore = StrategyScore::query()->where('strategy', 'macd_trend_v1')->first();
        $this->assertSame(4.2, $macdScore->regime_performance['trend_up']['profit_percent']);
        $this->assertSame(4.2, $macdScore->volatility_performance['high_volatility']['profit_percent']);

        $this->assertDatabaseHas('strategy_scores', [
            'strategy' => 'ema_rsi_v1',
            'score' => 76,
            'total_trades' => 248,
        ]);

        $session = TrainingSession::first();
        $this->assertNotNull($session);
        $this->assertSame(2, $session->strategyScores()->count());

        $this->assertDatabaseHas('model_versions', [
            'strategy' => 'macd_trend_v1',
            'version' => 'v1',
            'best_score' => 84,
            'status' => 'active',
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:9000/api/backtest/run-all'
            && $request['symbol'] === 'XAUUSD'
            && $request['timeframe'] === 'H1'
            && $request['strategy'] === 'all'
            && is_array($request['strategies'])
            && $request['initial_balance'] === 10000.0
            && $request['risk_per_trade'] === 1.0);
    }

    public function test_run_all_sends_model_version_parameters_to_ai_service(): void
    {
        ModelVersion::create([
            'name' => 'breakout_v2',
            'strategy' => 'breakout_v2',
            'version' => 'v2',
            'generation' => 2,
            'status' => 'testing',
            'parameters' => [
                'lookback' => 30,
                'atr_multiplier' => 0.4,
                'confirmation_candles' => 2,
            ],
            'metadata' => [],
        ]);

        Http::fake([
            'http://127.0.0.1:9000/api/backtest/run-all' => Http::response([
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'leaderboard' => [
                    [
                        'strategy' => 'breakout_v2',
                        'score' => 52,
                        'result' => [
                            'total_trades' => 40,
                            'wins' => 22,
                            'losses' => 18,
                            'winrate' => 55.0,
                            'net_profit_percent' => 4.2,
                            'max_drawdown' => 7.1,
                            'profit_factor' => 1.22,
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
        ]);

        Http::assertSent(function ($request): bool {
            $strategies = $request['strategies'];

            return $request->url() === 'http://127.0.0.1:9000/api/backtest/run-all'
                && count($strategies) === 1
                && $strategies[0]['strategy'] === 'breakout_v2'
                && $strategies[0]['base_strategy'] === 'breakout_v1'
                && $strategies[0]['version'] === 'v2'
                && $strategies[0]['parameters']['lookback'] === 30
                && $strategies[0]['parameters']['atr_multiplier'] === 0.4
                && $strategies[0]['parameters']['confirmation_candles'] === 2;
        });

        $this->assertDatabaseHas('strategy_scores', [
            'strategy' => 'breakout_v2',
            'score' => 52,
        ]);
    }

    public function test_run_all_rejects_weak_model_versions(): void
    {
        ModelVersion::create([
            'name' => 'breakout_v1',
            'strategy' => 'breakout_v1',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => [
                'lookback' => 20,
                'atr_multiplier' => 0.2,
            ],
            'metadata' => [],
        ]);

        Http::fake([
            'http://127.0.0.1:9000/api/backtest/run-all' => Http::response([
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'leaderboard' => [
                    [
                        'strategy' => 'breakout_v1',
                        'parameters' => [
                            'lookback' => 20,
                            'atr_multiplier' => 0.2,
                        ],
                        'score' => 20,
                        'result' => [
                            'total_trades' => 12,
                            'wins' => 3,
                            'losses' => 9,
                            'winrate' => 25.0,
                            'net_profit_percent' => -8.5,
                            'max_drawdown' => 14.8,
                            'profit_factor' => 0.62,
                            'regime_performance' => [
                                'range' => [
                                    'trades' => 8,
                                    'wins' => 2,
                                    'losses' => 6,
                                    'winrate' => 25.0,
                                    'profit_percent' => -7.2,
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
        ]);

        $this->assertDatabaseHas('model_versions', [
            'strategy' => 'breakout_v1',
            'version' => 'v1',
            'best_score' => 20,
            'status' => 'rejected',
        ]);

        $this->assertDatabaseHas('evolution_proposals', [
            'strategy' => 'breakout_v1',
            'current_version' => 'v1',
            'proposed_version' => 'v2',
            'current_score' => 20,
            'main_problem' => 'false_breakout',
            'status' => 'pending',
        ]);

        $proposal = EvolutionProposal::first();
        $this->assertStringContainsString('Eng yomon market regime: range', $proposal->reason);
        $this->assertSame('range', $proposal->new_parameters['avoid_regime']);
    }

    public function test_high_score_model_is_not_activated_when_risk_metrics_are_weak(): void
    {
        ModelVersion::create([
            'name' => 'fibonacci_v1',
            'strategy' => 'fibonacci_v1',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => ['lookback' => 50],
            'metadata' => [],
        ]);

        Http::fake([
            'http://127.0.0.1:9000/api/backtest/run-all' => Http::response([
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'leaderboard' => [
                    [
                        'strategy' => 'fibonacci_v1',
                        'parameters' => ['lookback' => 50],
                        'score' => 82,
                        'result' => [
                            'total_trades' => 120,
                            'wins' => 70,
                            'losses' => 50,
                            'winrate' => 58.3,
                            'net_profit_percent' => 12.0,
                            'max_drawdown_percent' => 22.5,
                            'profit_factor' => 0.95,
                            'stability_score' => 45,
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
        ]);

        $this->assertDatabaseHas('model_versions', [
            'strategy' => 'fibonacci_v1',
            'version' => 'v1',
            'best_score' => 82,
            'best_drawdown' => 22.5,
            'status' => 'testing',
        ]);
    }

    public function test_model_is_rejected_when_profit_factor_is_too_low_even_with_moderate_score(): void
    {
        ModelVersion::create([
            'name' => 'ema_rsi_v1',
            'strategy' => 'ema_rsi_v1',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => ['ema_fast' => 50],
            'metadata' => [],
        ]);

        Http::fake([
            'http://127.0.0.1:9000/api/backtest/run-all' => Http::response([
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'leaderboard' => [
                    [
                        'strategy' => 'ema_rsi_v1',
                        'parameters' => ['ema_fast' => 50],
                        'score' => 45,
                        'result' => [
                            'total_trades' => 80,
                            'wins' => 35,
                            'losses' => 45,
                            'winrate' => 43.75,
                            'net_profit_percent' => -2.5,
                            'max_drawdown_percent' => 12.0,
                            'profit_factor' => 0.72,
                            'stability_score' => 38,
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
        ]);

        $this->assertDatabaseHas('model_versions', [
            'strategy' => 'ema_rsi_v1',
            'version' => 'v1',
            'best_score' => 45,
            'status' => 'rejected',
        ]);
    }

    public function test_run_all_requires_testing_or_active_model_versions(): void
    {
        $response = $this->post('/strategy-lab/run-all', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Testing yoki active model version topilmadi.');
    }
}

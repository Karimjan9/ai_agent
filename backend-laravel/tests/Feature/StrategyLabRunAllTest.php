<?php

namespace Tests\Feature;

use App\Models\StrategyScore;
use App\Models\TrainingSession;
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
            'raw_result' => [],
        ]);

        $response = $this->get('/strategy-lab');

        $response->assertOk()
            ->assertSee('Strategy Lab')
            ->assertSee('Start New Training Session')
            ->assertSee('MACD_TREND_V1')
            ->assertSee('84');
    }

    public function test_run_all_posts_to_ai_service_and_stores_strategy_scores(): void
    {
        Http::fake([
            'http://127.0.0.1:9000/api/backtest/run-all' => Http::response([
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'leaderboard' => [
                    [
                        'strategy' => 'macd_trend_v1',
                        'score' => 84,
                        'result' => [
                            'total_trades' => 185,
                            'wins' => 113,
                            'losses' => 72,
                            'winrate' => 61.2,
                            'net_profit_percent' => 22.4,
                            'max_drawdown' => 6.2,
                            'profit_factor' => 1.74,
                        ],
                    ],
                    [
                        'strategy' => 'ema_rsi_v1',
                        'score' => 76,
                        'result' => [
                            'total_trades' => 248,
                            'wins' => 140,
                            'losses' => 108,
                            'winrate' => 56.4,
                            'net_profit_percent' => 18.5,
                            'max_drawdown' => 8.7,
                            'profit_factor' => 1.42,
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
        ]);

        $this->assertDatabaseHas('strategy_scores', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'macd_trend_v1',
            'score' => 84,
            'total_trades' => 185,
        ]);

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
            && $request['initial_balance'] === 10000.0
            && $request['risk_per_trade'] === 1.0);
    }

    public function test_run_all_rejects_weak_model_versions(): void
    {
        Http::fake([
            'http://127.0.0.1:9000/api/backtest/run-all' => Http::response([
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'leaderboard' => [
                    [
                        'strategy' => 'breakout_v1',
                        'score' => 20,
                        'result' => [
                            'total_trades' => 12,
                            'wins' => 3,
                            'losses' => 9,
                            'winrate' => 25.0,
                            'net_profit_percent' => -8.5,
                            'max_drawdown' => 14.8,
                            'profit_factor' => 0.62,
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
    }
}

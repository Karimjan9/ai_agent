<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Models\MarketSymbol;
use App\Models\TrainingLog;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoTrainingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.market_data.provider' => 'csv']);
    }

    public function test_training_logs_index_and_show_pages_are_visible(): void
    {
        $session = TrainingSession::create([
            'title' => 'Auto Training Session 2026-06-08 01:00',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'agents_count' => 1,
            'best_strategy' => 'ema_rsi_v1',
            'best_score' => 80,
            'worst_strategy' => 'ema_rsi_v1',
            'worst_score' => 80,
            'total_trades' => 120,
            'average_winrate' => 58.4,
            'average_profit' => 10.2,
            'ai_conclusion' => 'Auto training yaxshi yakunlandi.',
            'next_training_plan' => 'Keyingi treningda risk filter tekshiriladi.',
            'raw_leaderboard' => [],
        ]);

        $log = TrainingLog::create([
            'type' => 'auto_training',
            'status' => 'success',
            'training_session_id' => $session->id,
            'message' => 'Auto training muvaffaqiyatli yakunlandi.',
            'context' => ['best_strategy' => 'ema_rsi_v1'],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->get('/training-logs')
            ->assertOk()
            ->assertSee('Auto Training Logs')
            ->assertSee('auto_training')
            ->assertSee('success')
            ->assertSee('Session');

        $this->get(route('training-logs.show', $log))
            ->assertOk()
            ->assertSee('Training Log #'.$log->id)
            ->assertSee('Auto training muvaffaqiyatli yakunlandi.')
            ->assertSee('ema_rsi_v1')
            ->assertSee('Open Session');
    }

    public function test_auto_train_command_creates_session_scores_and_log(): void
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
                        'score' => 82,
                        'train_score' => 85,
                        'validation_score' => 83,
                        'forward_score' => 82,
                        'forward_window_scores' => [80, 82, 84],
                        'rolling_windows_count' => 3,
                        'robustness_score' => 97,
                        'is_overfit' => false,
                        'result' => [
                            'train_score' => 85,
                            'validation_score' => 83,
                            'forward_score' => 82,
                            'forward_window_scores' => [80, 82, 84],
                            'rolling_windows_count' => 3,
                            'robustness_score' => 97,
                            'is_overfit' => false,
                            'parameters' => ['ema_fast' => 50, 'ema_slow' => 200],
                            'total_trades' => 120,
                            'wins' => 72,
                            'losses' => 48,
                            'winrate' => 60.0,
                            'net_profit_percent' => 14.5,
                            'max_drawdown_percent' => 6.2,
                            'profit_factor' => 1.55,
                            'average_win_percent' => 1.0,
                            'average_loss_percent' => 0.6,
                            'risk_reward_ratio' => 1.67,
                            'max_consecutive_losses' => 3,
                            'stability_score' => 88,
                            'equity_curve' => [10000, 10100, 10250],
                            'regime_performance' => [
                                'trend_up' => [
                                    'trades' => 20,
                                    'wins' => 14,
                                    'losses' => 6,
                                    'winrate' => 70,
                                    'profit_percent' => 5.2,
                                ],
                            ],
                            'volatility_performance' => [],
                            'monte_carlo' => [
                                'simulations' => 1000,
                                'worst_profit_percent' => -3.5,
                                'avg_profit_percent' => 13.2,
                                'best_profit_percent' => 24.8,
                                'worst_drawdown_percent' => 11.4,
                                'avg_drawdown_percent' => 5.7,
                                'risk_of_ruin_percent' => 1.8,
                                'worst_equity_curve' => [10000, 9980, 10250],
                                'best_equity_curve' => [10000, 10400, 10800],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('trading:auto-train')
            ->expectsOutput('Auto training session yaratildi: #1')
            ->assertOk();

        $this->assertDatabaseHas('training_logs', [
            'type' => 'auto_training',
            'status' => 'success',
            'training_session_id' => 1,
        ]);

        $this->assertDatabaseHas('training_sessions', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'best_strategy' => 'ema_rsi_v1',
            'best_score' => 82,
            'average_profit_factor' => 1.55,
        ]);

        $this->assertDatabaseHas('strategy_scores', [
            'strategy' => 'ema_rsi_v1',
            'score' => 82,
            'robustness_score' => 97,
            'mc_risk_of_ruin_percent' => 1.8,
            'profit_factor' => 1.55,
            'stability_score' => 88,
        ]);

        $this->assertDatabaseHas('model_versions', [
            'strategy' => 'ema_rsi_v1',
            'version' => 'v1',
            'best_score' => 82,
            'status' => 'testing',
        ]);

        $this->assertDatabaseHas('model_market_performance', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'ema_rsi',
            'status' => 'forward_validated',
            'paper_status' => 'pending',
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:9000/api/backtest/run-all'
            && $request['strategy'] === 'all'
            && $request['strategies'][0]['strategy'] === 'ema_rsi_v1'
            && $request['strategies'][0]['base_strategy'] === 'ema_rsi_v1'
            && $request['strategies'][0]['parameters']['ema_fast'] === 50
            && is_array($request['candles']));
    }

    public function test_market_data_update_command_stores_csv_candles_without_duplicates(): void
    {
        MarketSymbol::create([
            'symbol' => 'XAUUSD',
            'provider_symbol' => 'XAU_USD',
            'name' => 'Gold / US Dollar',
            'market_type' => 'forex',
            'is_active' => true,
        ]);
        $this->writeMarketDataCsv();

        $this->artisan('market-data:update --symbol=XAUUSD --timeframe=H1 --limit=2')
            ->expectsOutput('XAUUSD H1: 2 candle updated.')
            ->assertOk();

        $this->artisan('market-data:update --symbol=XAUUSD --timeframe=H1 --limit=2')
            ->expectsOutput('XAUUSD H1: 2 candle updated.')
            ->assertOk();

        $this->assertDatabaseHas('symbols', [
            'code' => 'XAUUSD',
            'display_name' => 'Gold / US Dollar',
        ]);

        $this->assertDatabaseCount('candles', 2);
    }

    public function test_daily_workflow_runs_auto_training_and_daily_report(): void
    {
        MarketSymbol::create([
            'symbol' => 'XAUUSD',
            'provider_symbol' => 'XAU_USD',
            'name' => 'Gold / US Dollar',
            'market_type' => 'forex',
            'is_active' => true,
        ]);
        $this->writeMarketDataCsv();

        ModelVersion::create([
            'name' => 'breakout_v1',
            'strategy' => 'breakout_v1',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => ['lookback' => 20],
            'metadata' => [],
        ]);

        Http::fake([
            'http://127.0.0.1:9000/api/backtest/run-all' => Http::response([
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'leaderboard' => [
                    [
                        'strategy' => 'breakout_v1',
                        'parameters' => ['lookback' => 20],
                        'score' => 45,
                        'result' => [
                            'total_trades' => 35,
                            'wins' => 17,
                            'losses' => 18,
                            'winrate' => 48.5,
                            'net_profit_percent' => 1.2,
                            'max_drawdown_percent' => 9.5,
                            'profit_factor' => 1.05,
                            'stability_score' => 60,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('trading:daily-workflow')
            ->expectsOutput('1/3 Market data yangilanmoqda...')
            ->expectsOutput('2/3 Auto training boshlanmoqda...')
            ->expectsOutput('3/3 Daily report yaratilmoqda...')
            ->expectsOutput('Daily AI workflow yakunlandi.')
            ->assertOk();

        $this->assertDatabaseHas('training_logs', [
            'type' => 'daily_workflow',
            'status' => 'success',
        ]);

        $this->assertDatabaseHas('training_logs', [
            'type' => 'auto_training',
            'status' => 'success',
        ]);

        $this->assertDatabaseHas('training_sessions', [
            'best_strategy' => 'breakout_v1',
            'best_score' => 45,
        ]);

        $this->assertDatabaseCount('candles', 3);
    }

    private function writeMarketDataCsv(): void
    {
        $directory = storage_path('app/market-data');
        File::ensureDirectoryExists($directory);
        File::put($directory.'/XAUUSD_H1.csv', implode(PHP_EOL, [
            'time,open,high,low,close,volume',
            '2024-01-01 00:00:00,2062.12,2065.40,2059.10,2063.50,0',
            '2024-01-01 01:00:00,2063.50,2066.00,2060.00,2064.10,0',
            '2024-01-01 02:00:00,2064.10,2068.00,2062.00,2067.25,0',
        ]).PHP_EOL);
    }
}

<?php

namespace Tests\Feature;

use App\Models\LabEvaluationRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacktestWebFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_backtests_page_is_visible(): void
    {
        $response = $this->get('/backtests');

        $response->assertOk()
            ->assertSee('Run Canonical Lab Replay')
            ->assertSee('Initial balance');
    }

    public function test_backtest_run_posts_to_python_service_and_renders_result(): void
    {
        Http::fake([
            'http://127.0.0.1:9000/api/backtest/run' => Http::response([
                'strategy' => 'EMA_RSI_V1',
                'instrument' => 'XAU/USD',
                'timeframe' => 'H1',
                'period' => '2023-01-01 - 2025-12-31',
                'initial_balance' => 10000,
                'final_balance' => 11850,
                'net_profit_percent' => 18.5,
                'total_trades' => 248,
                'wins' => 140,
                'losses' => 108,
                'winrate' => 56.4,
                'profit_factor' => 1.42,
                'max_drawdown' => 8.7,
                'top_mistakes' => [
                    ['type' => 'sideways_market', 'count' => 1],
                ],
                'trades' => [
                    [
                        'direction' => 'BUY',
                        'entry_time' => '2023-01-01 01:00:00',
                        'exit_time' => '2023-01-01 02:00:00',
                        'entry_price' => 1823.50,
                        'exit_price' => 1814.38,
                        'stop_loss' => 1814.38,
                        'take_profit' => 1841.74,
                        'result' => 'LOSS',
                        'market_regime' => 'range',
                        'volatility_regime' => 'high_volatility',
                        'profit_percent' => -0.5,
                        'balance' => 9950,
                        'mistake_type' => 'sideways_market',
                        'reason' => 'EMA 50 va EMA 200 juda yaqin.',
                        'suggestion' => 'ATR volatility filter qo`shish kerak.',
                    ],
                ],
                'conclusion' => "Trend paytida yaxshi, flat bozorda ko'p xato qiladi.",
            ], 200),
        ]);

        $response = $this->post('/backtests/run', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'ema_rsi_v1',
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
            'from_date' => '2023-01-01',
            'to_date' => '2025-12-31',
        ]);

        $response->assertOk()
            ->assertSee('EMA_RSI_V1')
            ->assertSee('248')
            ->assertSee('56.4%')
            ->assertSee('sideways_market')
            ->assertSee("Trend paytida yaxshi, flat bozorda ko'p xato qiladi.");

        $run = LabEvaluationRun::query()->where('phase', 'manual_backtest')->latest('id')->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame(248, data_get($run->metrics, 'total_trades'));
        $this->assertDatabaseHas('lab_evidence_artifacts', [
            'run_id' => $run->run_id,
            'artifact_type' => 'evaluation_response',
        ]);
        $this->assertDatabaseCount('backtest_runs', 0);
        $this->assertDatabaseCount('trades', 0);
        $this->assertDatabaseCount('mistakes', 0);

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:9000/api/backtest/run'
            && $request['symbol'] === 'XAUUSD'
            && $request['timeframe'] === 'H1'
            && $request['strategy'] === 'ema_rsi_v1'
            && $request['initial_balance'] === 10000.0
            && $request['risk_per_trade'] === 1.0
            && $request['from_date'] === '2023-01-01'
            && $request['to_date'] === '2025-12-31');
    }

    public function test_daily_report_command_stores_training_report(): void
    {
        Http::fake([
            'http://127.0.0.1:9000/api/backtest/run' => Http::response([
                'strategy' => 'EMA_RSI_V1',
                'instrument' => 'XAU/USD',
                'timeframe' => 'H1',
                'period' => '2023-01-01 - 2025-12-31',
                'initial_balance' => 10000,
                'final_balance' => 11850,
                'net_profit_percent' => 18.5,
                'total_trades' => 248,
                'wins' => 140,
                'losses' => 108,
                'winrate' => 56.4,
                'profit_factor' => 1.42,
                'max_drawdown' => 8.7,
                'top_mistakes' => [
                    ['type' => 'sideways_market', 'count' => 1],
                ],
                'trades' => [
                    [
                        'direction' => 'BUY',
                        'entry_time' => '2023-01-01 01:00:00',
                        'exit_time' => '2023-01-01 02:00:00',
                        'entry_price' => 1823.50,
                        'exit_price' => 1814.38,
                        'stop_loss' => 1814.38,
                        'take_profit' => 1841.74,
                        'result' => 'LOSS',
                        'market_regime' => 'range',
                        'volatility_regime' => 'high_volatility',
                        'profit_percent' => -0.5,
                        'balance' => 9950,
                        'mistake_type' => 'sideways_market',
                        'reason' => 'EMA 50 va EMA 200 juda yaqin.',
                        'suggestion' => 'ATR volatility filter qo`shish kerak.',
                    ],
                ],
                'conclusion' => "Trend paytida yaxshi, flat bozorda ko'p xato qiladi.",
            ], 200),
        ]);

        $this->post('/backtests/run', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'ema_rsi_v1',
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
            'from_date' => '2023-01-01',
            'to_date' => '2025-12-31',
        ]);

        $this->artisan('trading:daily-report')
            ->expectsOutput('Daily AI report yaratildi.')
            ->assertOk();

        $this->assertDatabaseHas('daily_reports', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'ema_rsi_v1',
            'total_backtests' => 1,
            'total_trades' => 248,
            'total_wins' => 140,
            'total_losses' => 108,
        ]);
    }
}

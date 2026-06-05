<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BacktestRunApiTest extends TestCase
{
    public function test_backtest_run_endpoint_returns_summary_metrics(): void
    {
        Http::fake([
            '*' => Http::response([
                'metrics' => [
                    'total_trades' => 248,
                    'wins' => 140,
                    'losses' => 108,
                    'win_rate' => 56.4,
                    'net_pnl' => 124.5,
                    'profit_factor' => 1.42,
                    'max_drawdown' => 8.7,
                ],
                'daily_report' => [
                    'summary' => 'Generated 248 trades.',
                    'days' => [],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/backtest/run', [
            'symbol' => 'XAU_USD',
            'timeframe' => 'H1',
            'strategy' => 'ema_rsi_v1',
            'from' => '2023-01-01',
            'to' => '2025-12-31',
        ]);

        $response->assertOk()
            ->assertJson([
                'trades' => 248,
                'winrate' => 56.4,
                'profit_factor' => 1.42,
                'max_drawdown' => 8.7,
                'conclusion' => "EMA trend + RSI strategy H1 timeframe'da yaxshi ishladi, lekin sideways marketdagi xatolar mistake journal orqali tekshirilishi kerak.",
            ]);
    }
}

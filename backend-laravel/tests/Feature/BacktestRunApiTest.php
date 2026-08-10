<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacktestRunApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_idempotency_key_returns_the_same_run_for_the_same_payload(): void
    {
        Http::fake([
            '*' => Http::response([
                'metrics' => [
                    'total_trades' => 3,
                    'wins' => 2,
                    'losses' => 1,
                    'win_rate' => 66.6,
                    'net_pnl' => 1.2,
                    'profit_factor' => 1.5,
                    'max_drawdown' => 0.4,
                ],
            ], 200),
        ]);

        $payload = [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'ema_rsi_v1',
            'from' => '2023-01-01',
            'to' => '2025-12-31',
        ];

        $first = $this->withHeader('Idempotency-Key', 'manual-run-001')
            ->postJson('/api/backtest/run', $payload)
            ->assertOk();
        $second = $this->withHeader('Idempotency-Key', 'manual-run-001')
            ->postJson('/api/backtest/run', $payload)
            ->assertOk();

        $this->assertSame($first->json('run_id'), $second->json('run_id'));
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        Http::assertSentCount(1);
    }

    public function test_idempotency_key_cannot_be_reused_for_a_different_payload(): void
    {
        Http::fake([
            '*' => Http::response(['metrics' => ['total_trades' => 0]], 200),
        ]);

        $this->withHeader('Idempotency-Key', 'manual-run-002')
            ->postJson('/api/backtest/run', [
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'strategy' => 'ema_rsi_v1',
                'from' => '2023-01-01',
                'to' => '2025-12-31',
            ])
            ->assertOk();

        $this->withHeader('Idempotency-Key', 'manual-run-002')
            ->postJson('/api/backtest/run', [
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'strategy' => 'ema_rsi_v1',
                'from' => '2024-01-01',
                'to' => '2025-12-31',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        Http::assertSentCount(1);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.access_control.enforce_in_tests', true);
    }

    public function test_dashboard_requires_login_and_viewer_cannot_mutate(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->actingAs($viewer)->get('/')->assertOk();
        $this->actingAs($viewer)->post('/backtests/run')->assertForbidden();
    }

    public function test_internal_api_requires_shared_token(): void
    {
        config()->set('services.internal_api.token', 'test-shared-internal-token');
        Http::fake(['*' => Http::response([
            'strategy' => 'EMA_RSI_V1', 'instrument' => 'XAU/USD', 'timeframe' => 'H1',
            'period' => '2025-01-01 - 2025-01-02', 'total_trades' => 0, 'winrate' => 0,
            'profit_factor' => 0, 'max_drawdown' => 0, 'net_profit_percent' => 0,
        ])]);
        $payload = [
            'symbol' => 'XAU/USD', 'timeframe' => 'H1', 'strategy' => 'ema_rsi_v1',
            'from' => '2025-01-01', 'to' => '2025-01-02',
        ];

        $this->postJson('/api/backtest/run', $payload)->assertUnauthorized();
        $this->withHeader('X-Internal-Token', 'test-shared-internal-token')
            ->postJson('/api/backtest/run', $payload)->assertOk();
    }
}

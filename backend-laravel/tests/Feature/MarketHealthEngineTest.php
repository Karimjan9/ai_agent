<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\MarketProviderHealth;
use App\Models\ServiceHealthCheck;
use App\Models\Symbol;
use App\Models\SystemEvent;
use App\Models\SystemLog;
use App\Services\MarketHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketHealthEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_health_marks_fresh_mt5_feed_as_ok(): void
    {
        config([
            'services.mt5.symbols' => 'XAUUSD',
            'services.mt5.timeframes' => 'M15',
        ]);

        $this->createCandle(now()->subMinutes(3));

        $checks = app(MarketHealthService::class)->check();

        $this->assertCount(1, $checks);
        $this->assertSame('ok', $checks->first()->status);
        $this->assertDatabaseHas('market_provider_health', [
            'provider' => 'mt5',
            'symbol' => 'XAUUSD',
            'timeframe' => 'M15',
            'status' => 'ok',
        ]);
        $this->assertDatabaseHas('service_health_checks', [
            'service_key' => 'market_feed:mt5:XAUUSD:M15',
            'status' => 'ok',
        ]);
    }

    public function test_market_health_alerts_and_logs_lost_mt5_feed(): void
    {
        config([
            'services.mt5.symbols' => 'XAUUSD',
            'services.mt5.timeframes' => 'M15',
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '12345',
        ]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->createCandle(now()->subMinutes(30));

        $checks = app(MarketHealthService::class)->check();

        $this->assertSame('lost', $checks->first()->status);
        $this->assertDatabaseHas('market_provider_health', [
            'provider' => 'mt5',
            'symbol' => 'XAUUSD',
            'timeframe' => 'M15',
            'status' => 'lost',
            'alert_sent' => true,
        ]);
        $this->assertDatabaseHas('service_health_checks', [
            'service_key' => 'market_feed:mt5:XAUUSD:M15',
            'status' => 'critical',
        ]);
        $this->assertDatabaseHas('system_logs', [
            'log_type' => 'provider_lost',
            'component' => 'market_feed',
            'status' => 'lost',
        ]);
        $this->assertDatabaseHas('system_logs', [
            'log_type' => 'telegram_alert',
            'component' => 'telegram',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('system_events', [
            'event_type' => 'provider_lost',
            'symbol' => 'XAUUSD',
            'timeframe' => 'M15',
            'severity' => 'critical',
        ]);
        Http::assertSentCount(1);
    }

    public function test_market_health_command_runs(): void
    {
        config([
            'services.mt5.symbols' => 'XAUUSD',
            'services.mt5.timeframes' => 'M15',
        ]);

        $this->artisan('market:health')
            ->expectsOutputToContain('Market health checked')
            ->assertExitCode(0);
    }

    private function createCandle($time): Candle
    {
        $symbol = Symbol::create([
            'code' => 'XAUUSD',
            'display_name' => 'Gold',
            'asset_class' => 'metal',
            'is_active' => true,
        ]);

        return Candle::create([
            'symbol_id' => $symbol->id,
            'timeframe' => 'M15',
            'time' => $time,
            'open' => 3345.12,
            'high' => 3346.88,
            'low' => 3344.81,
            'close' => 3346.01,
            'volume' => 1532,
            'provider' => 'mt5',
        ]);
    }
}

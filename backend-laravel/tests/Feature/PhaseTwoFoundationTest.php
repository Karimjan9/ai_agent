<?php

namespace Tests\Feature;

use App\Models\AgentMemory;
use App\Models\AgentMemoryMatch;
use App\Models\MarketDataSyncState;
use App\Models\MarketSpecies;
use App\Models\MarketStateSnapshot;
use App\Models\ServiceHealthCheck;
use App\Models\SignalMarketSnapshot;
use App\Models\SystemEvent;
use App\Models\User;
use App\Services\LabPopulationService;
use App\Services\PhaseTwoFoundationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PhaseTwoFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_records_events_snapshots_and_agent_memory_matches(): void
    {
        $foundation = app(PhaseTwoFoundationService::class);
        $species = MarketSpecies::create([
            'code' => 'bull_expansion',
            'name' => 'Bull Expansion',
            'dominant_state' => 'trend_up',
            'description' => 'Trending market with expansion.',
            'danger_score' => 25,
            'opportunity_score' => 82,
            'signature' => [],
        ]);
        MarketStateSnapshot::create([
            'market_species_id' => $species->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'time' => now(),
            'market_state' => 'trend_up',
            'liquidity_state' => 'normal',
            'momentum_state' => 'strong',
            'structure_state' => 'breakout',
            'confidence_score' => 88,
            'trend_score' => 82,
            'panic_score' => 4,
            'compression_score' => 18,
            'expansion_score' => 34,
            'momentum_score' => 76,
            'liquidity_proxy_score' => 74,
            'features' => [],
            'explanation' => 'Test snapshot.',
        ]);

        $foundation->writeExperienceMemory([
            'strategy' => 'ema_rsi_v8',
            'market_regime' => 'trend',
            'volatility_regime' => 'breakout',
            'market_species' => 'Bull Expansion',
            'outcome' => 'success',
            'summary' => 'Bull Expansion worked for trend continuation.',
            'lesson' => 'Trend continuation signals are stronger during Bull Expansion.',
            'strength' => 85,
            'confidence_score' => 91,
        ]);

        $signal = $foundation->captureSignalMarketSnapshot([
            'strategy' => 'ema_rsi_v8',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'signal' => 'BUY',
            'confidence' => 82,
            'hypothesis' => 'Trend continues for the next 20 candles.',
        ]);

        $this->assertNotNull($signal);
        $this->assertSame(1, SignalMarketSnapshot::count());
        $this->assertSame(1, AgentMemory::count());
        $this->assertGreaterThan(0, AgentMemoryMatch::count());
        $this->assertGreaterThan(0, SystemEvent::count());
        $this->assertGreaterThan(0, (float) $signal->fresh()->memory_match_score);
    }

    public function test_health_center_dashboard_manual_check_and_command_work(): void
    {
        $this->post(route('agent-health.check'))
            ->assertRedirect(route('agent-health.index'));

        $this->get(route('agent-health.index'))
            ->assertOk()
            ->assertSee('Agent Health')
            ->assertSee('Service Health')
            ->assertSee('Event Store')
            ->assertSee('Market Snapshot');

        $this->artisan('system:health-check')
            ->expectsOutputToContain('System health checked')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, ServiceHealthCheck::count());
    }

    public function test_scheduler_health_requires_a_fresh_runtime_heartbeat(): void
    {
        $missing = app(PhaseTwoFoundationService::class)->runHealthCheck()
            ->firstWhere('service_key', 'scheduler');
        $this->assertSame('warning', $missing->status);

        Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(10));
        $fresh = app(PhaseTwoFoundationService::class)->runHealthCheck()
            ->firstWhere('service_key', 'scheduler');

        $this->assertSame('ok', $fresh->status);
        $this->assertSame(100.0, (float) $fresh->health_score);
    }

    public function test_access_control_health_requires_an_active_admin(): void
    {
        $missing = app(PhaseTwoFoundationService::class)->runHealthCheck()
            ->firstWhere('service_key', 'access_control');
        $this->assertSame('critical', $missing->status);

        User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $ready = app(PhaseTwoFoundationService::class)->runHealthCheck()
            ->firstWhere('service_key', 'access_control');

        $this->assertSame('ok', $ready->status);
        $this->assertSame(100.0, (float) $ready->health_score);
    }

    public function test_reality_loop_marks_p0_freeze_as_intentional_policy_state(): void
    {
        config(['services.secondary_intelligence.enabled' => false]);

        $check = app(PhaseTwoFoundationService::class)->runHealthCheck()
            ->firstWhere('service_key', 'reality_loop');

        $this->assertSame('ok', $check->status);
        $this->assertStringContainsString('intentionally frozen', $check->message);
        $this->assertFalse((bool) data_get($check->metrics, 'enabled'));
    }

    public function test_lab_pipeline_health_rejects_an_active_generation_with_a_terminal_timestamp(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'health_boundary_test', true);
        $generation->update(['status' => 'full_validation', 'completed_at' => now()]);

        $check = app(PhaseTwoFoundationService::class)->runHealthCheck()
            ->firstWhere('service_key', 'lab_pipeline');

        $this->assertSame('critical', $check->status);
        $this->assertStringContainsString('terminal completed_at', $check->message);
        $this->assertContains($generation->id, data_get($check->metrics, 'inconsistent_generation_ids'));
        $this->assertFalse((bool) data_get($check->metrics, 'promotion_evidence'));
    }

    public function test_lab_pipeline_health_tracks_active_work_without_calling_it_promotion_evidence(): void
    {
        app(LabPopulationService::class)->build('EURUSD', 'health_active_test', true);

        $check = app(PhaseTwoFoundationService::class)->runHealthCheck()
            ->firstWhere('service_key', 'lab_pipeline');

        $this->assertSame('ok', $check->status);
        $this->assertSame(1, (int) data_get($check->metrics, 'active_generations'));
        $this->assertSame(20, (int) data_get($check->metrics, 'active_agents'));
        $this->assertFalse((bool) data_get($check->metrics, 'promotion_evidence'));
    }

    public function test_lab_pipeline_health_blocks_full_validation_without_foundation_coverage(): void
    {
        $generation = app(LabPopulationService::class)->build('GBPUSD', 'health_coverage_test', true);
        $generation->update(['status' => 'full_validation', 'completed_at' => null]);

        $check = app(PhaseTwoFoundationService::class)->runHealthCheck()
            ->firstWhere('service_key', 'lab_pipeline');

        $this->assertSame('critical', $check->status);
        $this->assertStringContainsString('insufficient foundation/rolling dataset coverage', $check->message);
        $this->assertContains($generation->id, data_get($check->metrics, 'coverage_blocked_generation_ids'));
    }

    public function test_market_feed_health_rejects_a_stale_confirmed_candle(): void
    {
        config([
            'services.mt5.provider' => 'twelve',
            'services.mt5.symbols' => 'XAUUSD',
            'services.mt5.timeframes' => 'H1',
            'services.mt5.feed_stale_after_seconds' => 900,
            'services.mt5.feed_lost_after_seconds' => 1200,
        ]);
        MarketDataSyncState::create([
            'provider' => 'twelve',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'status' => 'healthy',
            'last_confirmed_candle_at' => now()->subHours(2),
        ]);

        $check = app(PhaseTwoFoundationService::class)->runHealthCheck()
            ->firstWhere('service_key', 'market_feed');

        $this->assertSame('critical', $check->status);
        $this->assertStringContainsString('candle age', $check->message);
    }
}

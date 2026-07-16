<?php

namespace Tests\Feature;

use App\Models\AgentMemory;
use App\Models\AgentMemoryMatch;
use App\Models\MarketSpecies;
use App\Models\MarketStateSnapshot;
use App\Models\ServiceHealthCheck;
use App\Models\SignalMarketSnapshot;
use App\Models\SystemEvent;
use App\Services\PhaseTwoFoundationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

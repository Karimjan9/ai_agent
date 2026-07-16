<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\MarketDiscovery;
use App\Models\MarketGenome;
use App\Models\MarketMemory;
use App\Models\MarketSpecies;
use App\Models\MarketStateProbability;
use App\Models\MarketStateSnapshot;
use App\Models\StrategyScore;
use App\Models\StrategySpeciesPerformance;
use App\Models\Symbol;
use App\Models\TrainingSession;
use App\Services\MarketRealityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketRealityEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_reality_analyzes_candles_into_species_genomes_memories_and_discoveries(): void
    {
        $symbol = $this->createMarketCandles();

        app(MarketRealityService::class)->analyzeSymbol($symbol, 'H1', 80);

        $this->assertGreaterThan(0, MarketStateSnapshot::count());
        $this->assertGreaterThan(0, MarketGenome::count());
        $this->assertGreaterThan(0, MarketSpecies::count());
        $this->assertGreaterThan(0, MarketStateProbability::count());
        $this->assertGreaterThan(0, MarketMemory::count());
        $this->assertGreaterThan(0, MarketDiscovery::count());

        $latestSnapshot = MarketStateSnapshot::query()->latest('time')->firstOrFail();

        $this->assertNotNull($latestSnapshot->marketSpecies);
        $this->assertNotNull($latestSnapshot->genome);
        $this->assertArrayHasKey('trend_score', $latestSnapshot->features);
        $this->assertArrayHasKey('liquidity_proxy_score', $latestSnapshot->features);
        $this->assertStringContainsString('liquidity_proxy', $latestSnapshot->explanation);
    }

    public function test_training_session_records_strategy_performance_by_market_species(): void
    {
        $symbol = $this->createMarketCandles();
        app(MarketRealityService::class)->analyzeSymbol($symbol, 'H1', 80);

        $session = TrainingSession::create([
            'title' => 'Session #18',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'agents_count' => 1,
            'best_strategy' => 'ema_rsi_v1',
            'best_score' => 81,
            'worst_strategy' => 'breakout_v1',
            'worst_score' => 44,
            'total_trades' => 18,
            'average_winrate' => 63,
            'average_profit' => 4.2,
            'ai_conclusion' => 'Market Reality test session.',
            'next_training_plan' => 'Compare strategy behavior by species.',
            'raw_leaderboard' => [],
            'status' => 'completed',
        ]);

        StrategyScore::create([
            'training_session_id' => $session->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'ema_rsi_v1',
            'parameters' => ['ema_fast' => 50, 'ema_slow' => 200],
            'score' => 81,
            'total_trades' => 18,
            'wins' => 12,
            'losses' => 6,
            'winrate' => 66.67,
            'net_profit_percent' => 4.2,
            'max_drawdown_percent' => 1.4,
            'profit_factor' => 1.7,
            'robustness_score' => 77,
            'raw_result' => [],
        ]);

        app(MarketRealityService::class)->recordStrategyPerformance($session);

        $this->assertDatabaseHas('strategy_species_performance', [
            'training_session_id' => $session->id,
            'strategy' => 'ema_rsi_v1',
            'trades' => 18,
        ]);

        $performance = StrategySpeciesPerformance::firstOrFail();

        $this->assertNotNull($performance->marketSpecies);
        $this->assertNotEmpty($performance->species_name);
        $this->assertArrayHasKey('profit_factor', $performance->evidence);
    }

    public function test_reanalysis_keeps_snapshot_observation_times_immutable(): void
    {
        $symbol = $this->createMarketCandles();
        $service = app(MarketRealityService::class);

        $service->analyzeSymbol($symbol, 'H1', 80);
        $before = MarketStateSnapshot::query()->latest('time')->firstOrFail();
        $observedAt = $before->time->toDateTimeString();

        $service->analyzeSymbol($symbol, 'H1', 80);
        $after = MarketStateSnapshot::findOrFail($before->id);

        $this->assertSame($observedAt, $after->time->toDateTimeString());
        $this->assertSame(40, MarketStateSnapshot::count());
    }

    public function test_market_intelligence_dashboard_renders_reality_layers(): void
    {
        $symbol = $this->createMarketCandles();
        app(MarketRealityService::class)->analyzeSymbol($symbol, 'H1', 80);

        $this->get(route('market-intelligence.index'))
            ->assertOk()
            ->assertSee('Market Intelligence')
            ->assertSee('Current Market Genome')
            ->assertSee('Species Library')
            ->assertSee('Similarity Scanner')
            ->assertSee('Discoveries');
    }

    private function createMarketCandles(): Symbol
    {
        $symbol = Symbol::create([
            'code' => 'XAUUSD',
            'display_name' => 'Gold / US Dollar',
            'asset_class' => 'forex',
            'is_active' => true,
        ]);

        $time = Carbon::parse('2026-01-01 00:00:00');
        $price = 2000.0;

        for ($i = 0; $i < 60; $i++) {
            $open = $price;
            $step = $i < 30 ? 2.4 : ($i % 5 === 0 ? 8.0 : 1.1);
            $close = $open + $step;
            $high = $close + ($i % 9 === 0 ? 12.0 : 2.2);
            $low = $open - ($i % 11 === 0 ? 8.0 : 1.4);

            if ($i === 38 || $i === 49) {
                $high = $open + 24.0;
                $low = $open - 18.0;
                $close = $open - 3.0;
            }

            Candle::create([
                'symbol_id' => $symbol->id,
                'timeframe' => 'H1',
                'time' => $time->copy()->addHours($i),
                'open' => round($open, 2),
                'high' => round(max($high, $open, $close), 2),
                'low' => round(min($low, $open, $close), 2),
                'close' => round($close, 2),
                'volume' => $i % 7 === 0 ? 4500 : 1200 + ($i * 35),
                'provider' => 'test',
            ]);

            $price = $close;
        }

        return $symbol;
    }
}

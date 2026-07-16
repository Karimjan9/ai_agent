<?php

namespace Tests\Feature;

use App\Models\MarketStateSnapshot;
use App\Models\MarketSymbol;
use App\Models\StrategyScore;
use App\Models\SymbolProfile;
use App\Services\InstrumentIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstrumentIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_instrument_profiles_are_created_for_xauusd_and_eurusd(): void
    {
        $this->seedSymbolsAndEvidence();

        $profiles = app(InstrumentIntelligenceService::class)->refresh(['XAUUSD', 'EURUSD'], ['M15', 'H1']);

        $this->assertCount(4, $profiles);
        $this->assertDatabaseHas('symbol_profiles', ['symbol' => 'XAUUSD', 'timeframe' => 'M15']);
        $this->assertDatabaseHas('symbol_profiles', ['symbol' => 'EURUSD', 'timeframe' => 'H1']);
        $this->assertGreaterThan(0, SymbolProfile::where('symbol', 'XAUUSD')->avg('news_sensitivity_score'));
    }

    public function test_market_profiles_dashboard_manual_refresh_and_command_work(): void
    {
        $this->seedSymbolsAndEvidence();

        $this->post(route('market-profiles.refresh'))
            ->assertRedirect(route('market-profiles.index'));

        $this->get(route('market-profiles.index'))
            ->assertOk()
            ->assertSee('Market Profiles')
            ->assertSee('Instrument Intelligence')
            ->assertSee('Market Brains');

        $this->artisan('profiles:refresh --symbol=XAUUSD --symbol=EURUSD --timeframe=M15 --timeframe=H1')
            ->expectsOutputToContain('Symbol profiles refreshed')
            ->assertExitCode(0);
    }

    private function seedSymbolsAndEvidence(): void
    {
        foreach ([
            ['symbol' => 'XAUUSD', 'category' => 'metals', 'priority' => 10],
            ['symbol' => 'EURUSD', 'category' => 'forex', 'priority' => 20],
        ] as $symbol) {
            MarketSymbol::create([
                'symbol' => $symbol['symbol'],
                'provider_symbol' => str_replace('USD', '_USD', $symbol['symbol']),
                'name' => $symbol['symbol'],
                'market_type' => 'forex',
                'category' => $symbol['category'],
                'priority' => $symbol['priority'],
                'is_active' => true,
            ]);

            foreach (['M15', 'H1'] as $timeframe) {
                StrategyScore::create([
                    'symbol' => $symbol['symbol'],
                    'timeframe' => $timeframe,
                    'strategy' => $symbol['symbol'] === 'XAUUSD' ? 'trend_agent' : 'breakout_agent',
                    'score' => 72,
                    'total_trades' => 24,
                    'wins' => 16,
                    'losses' => 8,
                    'winrate' => 66,
                    'net_profit_percent' => 4.5,
                    'max_drawdown_percent' => 3.2,
                    'profit_factor' => 1.8,
                    'raw_result' => [],
                ]);

                MarketStateSnapshot::create([
                    'symbol' => $symbol['symbol'],
                    'timeframe' => $timeframe,
                    'time' => now(),
                    'market_state' => 'trend_up',
                    'liquidity_state' => 'normal',
                    'momentum_state' => 'strong',
                    'structure_state' => 'breakout',
                    'confidence_score' => 84,
                    'trend_score' => 78,
                    'panic_score' => $symbol['symbol'] === 'XAUUSD' ? 26 : 8,
                    'compression_score' => 18,
                    'expansion_score' => $symbol['symbol'] === 'XAUUSD' ? 62 : 34,
                    'momentum_score' => 72,
                    'liquidity_proxy_score' => 68,
                    'features' => [],
                    'explanation' => 'Test profile state.',
                ]);
            }
        }
    }
}

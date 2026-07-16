<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\MarketSymbol;
use App\Models\Symbol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketDataPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.market_data.provider' => 'csv']);
    }

    public function test_market_data_index_shows_symbol_status(): void
    {
        $marketSymbol = MarketSymbol::create([
            'symbol' => 'XAUUSD',
            'provider_symbol' => 'XAU_USD',
            'name' => 'Gold / US Dollar',
            'market_type' => 'forex',
            'is_active' => true,
        ]);
        $symbol = Symbol::create([
            'code' => $marketSymbol->symbol,
            'display_name' => $marketSymbol->name,
            'asset_class' => 'forex',
            'is_active' => true,
        ]);

        Candle::create([
            'symbol_id' => $symbol->id,
            'timeframe' => 'H1',
            'time' => '2024-01-01 02:00:00',
            'open' => 2064.10,
            'high' => 2068.00,
            'low' => 2062.00,
            'close' => 2067.25,
            'volume' => 0,
            'provider' => 'csv',
        ]);

        $this->get('/market-data')
            ->assertOk()
            ->assertSee('Market Data')
            ->assertSee('XAUUSD')
            ->assertSee('Gold / US Dollar')
            ->assertSee('XAU_USD')
            ->assertSee('2024-01-01 02:00');
    }

    public function test_market_data_update_posts_to_command_and_stores_candles(): void
    {
        MarketSymbol::create([
            'symbol' => 'XAUUSD',
            'provider_symbol' => 'XAU_USD',
            'name' => 'Gold / US Dollar',
            'market_type' => 'forex',
            'is_active' => true,
        ]);
        $this->writeMarketDataCsv();

        $response = $this->post(route('market-data.update'), [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'limit' => 2,
        ]);

        $response->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('symbols', [
            'code' => 'XAUUSD',
            'display_name' => 'Gold / US Dollar',
        ]);
        $this->assertDatabaseCount('candles', 2);
    }

    public function test_market_data_update_can_store_oanda_candles(): void
    {
        config([
            'services.market_data.provider' => 'oanda',
            'services.oanda.token' => 'test-token',
            'services.oanda.base_url' => 'https://api-fxpractice.oanda.com',
        ]);

        MarketSymbol::create([
            'symbol' => 'XAUUSD',
            'provider_symbol' => 'XAU_USD',
            'name' => 'Gold / US Dollar',
            'market_type' => 'forex',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api-fxpractice.oanda.com/v3/instruments/XAU_USD/candles*' => Http::response([
                'instrument' => 'XAU_USD',
                'granularity' => 'H1',
                'candles' => [
                    [
                        'complete' => true,
                        'time' => '2024-01-01T00:00:00.000000000Z',
                        'volume' => 1100,
                        'mid' => ['o' => '2062.12', 'h' => '2065.40', 'l' => '2059.10', 'c' => '2063.50'],
                    ],
                    [
                        'complete' => false,
                        'time' => '2024-01-01T01:00:00.000000000Z',
                        'volume' => 900,
                        'mid' => ['o' => '2063.50', 'h' => '2066.00', 'l' => '2060.00', 'c' => '2064.10'],
                    ],
                    [
                        'complete' => true,
                        'time' => '2024-01-01T02:00:00.000000000Z',
                        'volume' => 1200,
                        'mid' => ['o' => '2064.10', 'h' => '2068.00', 'l' => '2062.00', 'c' => '2067.25'],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('market-data:update --symbol=XAUUSD --timeframe=H1 --limit=5000')
            ->expectsOutput('XAUUSD H1: 2 candle updated.')
            ->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && str_contains($request->url(), '/v3/instruments/XAU_USD/candles')
            && $request['granularity'] === 'H1'
            && $request['count'] === 5000);

        $this->assertDatabaseHas('candles', [
            'timeframe' => 'H1',
            'provider' => 'oanda',
            'close' => 2067.25,
        ]);
        $this->assertDatabaseCount('candles', 2);
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

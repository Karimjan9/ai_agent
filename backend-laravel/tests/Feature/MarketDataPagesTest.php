<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\MarketSymbol;
use App\Models\Symbol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MarketDataPagesTest extends TestCase
{
    use RefreshDatabase;

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

<?php

namespace Tests\Feature;

use App\Services\MarketData\TwelveDataMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwelveDataMarketDataProviderTest extends TestCase
{
    public function test_it_normalizes_twelve_data_utc_candles(): void
    {
        config()->set('services.twelve_data.api_key', 'test-key');
        Http::fake(['api.twelvedata.com/*' => Http::response([
            'status' => 'ok',
            'values' => [[
                'datetime' => '2026-07-16 05:00:00', 'open' => '1.1700', 'high' => '1.1710',
                'low' => '1.1690', 'close' => '1.1705', 'volume' => '123',
            ]],
        ])]);

        $candles = app(TwelveDataMarketDataProvider::class)->fetchCandles(
            'EURUSD', 'eurusd', 'H1', 10,
            CarbonImmutable::parse('2026-07-16 05:00:00', 'UTC'),
            CarbonImmutable::parse('2026-07-16 06:00:00', 'UTC'),
        );

        $this->assertSame('2026-07-16 05:00:00', $candles[0]['time']);
        $this->assertSame(1.1705, $candles[0]['close']);
        Http::assertSent(fn ($request) => $request->url() && str_contains($request->url(), 'symbol=EUR%2FUSD'));
    }
}

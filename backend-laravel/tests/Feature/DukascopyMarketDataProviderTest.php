<?php

namespace Tests\Feature;

use App\Services\MarketData\DukascopyMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DukascopyMarketDataProviderTest extends TestCase
{
    public function test_windows_uses_the_http_m15_adapter_without_a_node_child_process(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only console suppression policy.');
        }

        $this->assertFalse((bool) config('services.dukascopy.m15_node_enabled'));
    }

    public function test_it_decodes_jettas_h1_history_without_a_child_process(): void
    {
        config([
            'services.dukascopy.transport' => 'jetta',
            'services.dukascopy.jetta_base_url' => 'https://jetta.test',
            'services.dukascopy.http_retry_attempts' => 1,
            'services.dukascopy.tick_fallback_enabled' => false,
        ]);

        Http::fake([
            'https://jetta.test/v1/candles/trade/hour/EUR-USD/BID/2020/1' => Http::response([
                'timestamp' => CarbonImmutable::parse('2020-01-01 00:00:00', 'UTC')->getTimestampMs(),
                'shift' => 3_600_000,
                'multiplier' => 0.00001,
                'open' => 1.1,
                'high' => 1.2,
                'low' => 1.0,
                'close' => 1.15,
                'times' => [1, 1],
                'opens' => [1, 2],
                'highs' => [1, 2],
                'lows' => [1, 2],
                'closes' => [1, 2],
                'volumes' => [10.5, 11.5],
            ]),
        ]);

        $rows = app(DukascopyMarketDataProvider::class)->fetchCandles(
            'EURUSD',
            'EUR/USD',
            'H1',
            100,
            CarbonImmutable::parse('2020-01-01 01:00:00', 'UTC'),
            CarbonImmutable::parse('2020-01-01 03:00:00', 'UTC'),
        );

        $this->assertCount(2, $rows);
        $this->assertSame('2020-01-01 01:00:00', $rows[0]['time']);
        $this->assertEqualsWithDelta(1.10001, $rows[0]['open'], 0.000001);
        $this->assertSame(10.5, $rows[0]['volume']);
        $this->assertEqualsWithDelta(1.10003, $rows[1]['open'], 0.000001);
        Http::assertSentCount(1);
    }

    public function test_it_rebuilds_a_sparse_h1_archive_hour_from_ticks(): void
    {
        config([
            'services.dukascopy.transport' => 'jetta',
            'services.dukascopy.jetta_base_url' => 'https://jetta.test',
            'services.dukascopy.http_retry_attempts' => 1,
            'services.dukascopy.tick_fallback_enabled' => true,
        ]);

        $hour = CarbonImmutable::parse('2020-01-02 01:00:00', 'UTC');
        Http::fake([
            'https://jetta.test/v1/candles/trade/hour/EUR-USD/BID/2020/1' => Http::response([
                'timestamp' => $hour->getTimestampMs(),
                'shift' => 3_600_000,
                'multiplier' => 0.00001,
                'open' => 1.0,
                'high' => 1.0,
                'low' => 1.0,
                'close' => 1.0,
                'times' => [0],
                'opens' => [0],
                'highs' => [0],
                'lows' => [0],
                'closes' => [0],
                'volumes' => [1],
            ]),
            'https://jetta.test/v1/ticks/EUR-USD/2020/1/2/1' => Http::response([
                'timestamp' => $hour->getTimestampMs(),
                'multiplier' => 0.00001,
                'bid' => 1.1,
                'times' => [1000, 1000],
                'bids' => [0, 1],
                'bidVolumes' => [10_000_000, 20_000_000],
            ]),
        ]);

        $rows = app(DukascopyMarketDataProvider::class)->fetchCandles(
            'EURUSD', 'EUR/USD', 'H1', 100, $hour, $hour->addHour(),
        );

        $this->assertCount(1, $rows);
        $this->assertSame('2020-01-02 01:00:00', $rows[0]['time']);
        $this->assertEqualsWithDelta(1.1, $rows[0]['open'], 0.000001);
        $this->assertEqualsWithDelta(1.10001, $rows[0]['close'], 0.000001);
        $this->assertEqualsWithDelta(30.0, $rows[0]['volume'], 0.000001);
        Http::assertSentCount(2);
    }

    public function test_it_aggregates_jettas_minute_history_into_m15_tick_volume(): void
    {
        config([
            'services.dukascopy.transport' => 'jetta',
            'services.dukascopy.jetta_base_url' => 'https://jetta.test',
            'services.dukascopy.http_retry_attempts' => 1,
            'services.dukascopy.tick_fallback_enabled' => false,
            'services.dukascopy.m15_node_enabled' => false,
        ]);

        $start = CarbonImmutable::parse('2020-01-02 00:00:00', 'UTC');
        $times = array_merge([0], array_fill(0, 29, 1));
        $opens = array_fill(0, 30, 0);
        $highs = array_merge([2], array_fill(0, 29, 0));
        $lows = array_merge([-2], array_fill(0, 29, 0));
        $closes = array_merge([1], array_fill(0, 29, 0));
        $volumes = array_fill(0, 30, 1);

        Http::fake([
            'https://jetta.test/v1/candles/minute/EUR-USD/BID/2020/1/2' => Http::response([
                'timestamp' => $start->getTimestampMs(),
                'shift' => 60_000,
                'multiplier' => 1,
                'open' => 100,
                'high' => 100,
                'low' => 100,
                'close' => 100,
                'times' => $times,
                'opens' => $opens,
                'highs' => $highs,
                'lows' => $lows,
                'closes' => $closes,
                'volumes' => $volumes,
            ]),
        ]);

        $rows = app(DukascopyMarketDataProvider::class)->fetchCandles(
            'EURUSD', 'EUR/USD', 'M15', 100, $start, $start->addMinutes(30),
        );

        $this->assertCount(2, $rows);
        $this->assertSame('2020-01-02 00:00:00', $rows[0]['time']);
        $this->assertSame('2020-01-02 00:15:00', $rows[1]['time']);
        $this->assertSame(15.0, $rows[0]['volume']);
        $this->assertEqualsWithDelta(102.0, $rows[0]['high'], 0.000001);
        $this->assertEqualsWithDelta(101.0, $rows[1]['close'], 0.000001);
        Http::assertSentCount(1);
    }

    public function test_it_uses_timestamp_history_directly_for_the_open_month(): void
    {
        config([
            'services.dukascopy.transport' => 'jetta',
            'services.dukascopy.jetta_base_url' => 'https://jetta.test',
            'services.dukascopy.http_retry_attempts' => 1,
            'services.dukascopy.tick_fallback_enabled' => false,
        ]);

        // Keep this test anchored to the actual open month. A hard-coded
        // month eventually becomes an archive request as time advances and
        // makes the fake miss, causing an accidental DNS/network call.
        $hour = CarbonImmutable::now('UTC')->startOfMonth()->addDay()->setTime(10, 0);
        Http::fake([
            'https://jetta.test/v1/candles/trade/hour/EUR-USD/BID?from=*' => Http::response([
                'timestamp' => $hour->getTimestampMs(),
                'shift' => 3_600_000,
                'multiplier' => 0.00001,
                'open' => 1.17,
                'high' => 1.18,
                'low' => 1.16,
                'close' => 1.175,
                'times' => [0],
                'opens' => [0],
                'highs' => [0],
                'lows' => [0],
                'closes' => [0],
                'volumes' => [100],
            ]),
        ]);

        $rows = app(DukascopyMarketDataProvider::class)->fetchCandles(
            'EURUSD', 'EUR/USD', 'H1', 10, $hour, $hour->addHour(),
        );

        $this->assertCount(1, $rows);
        $this->assertSame($hour->format('Y-m-d H:i:s'), $rows[0]['time']);
        Http::assertSentCount(1);
    }
}

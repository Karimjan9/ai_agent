<?php

namespace Tests\Feature;

use App\Services\MarketData\HistoricalQuoteSpreadService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HistoricalQuoteSpreadTest extends TestCase
{
    private string $isolatedStorage;

    private string $sourceHash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedStorage = sys_get_temp_dir().'/quote-spread-test-'.bin2hex(random_bytes(8));
        $this->app->useStoragePath($this->isolatedStorage);
        $this->sourceHash = str_repeat('a', 64);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->isolatedStorage)) {
            $this->assertSame(realpath(sys_get_temp_dir()), realpath(dirname($this->isolatedStorage)));
            $this->assertStringStartsWith('quote-spread-test-', basename($this->isolatedStorage));
            File::deleteDirectory($this->isolatedStorage);
        }
        parent::tearDown();
    }

    public function test_new_stream_admits_exact_synchronized_quote_and_preserves_missing_rows(): void
    {
        $this->artifact();
        $result = app(HistoricalQuoteSpreadService::class)->attach($this->rows(), $this->sourceHash);
        $this->assertSame('partial', $result['provenance']['status']);
        $this->assertSame(1, $result['provenance']['available_rows']);
        $this->assertTrue($result['rows'][0]['spread_available']);
        $this->assertSame(0.5, $result['rows'][0]['spread']);
        $this->assertFalse($result['rows'][1]['spread_available']);
        $this->assertNull($result['rows'][1]['spread']);
        $this->assertFalse($result['provenance']['promotion_evidence']);
        $this->assertFalse($result['provenance']['independent_validation_evidence']);
        $this->assertFalse($result['provenance']['execution_cost_model_changed']);
    }

    public function test_bar_close_artifact_and_different_frozen_stream_have_no_quote_authority(): void
    {
        $this->artifact(['protocol' => 'research_quote_spread_sidecar_v1']);
        $result = app(HistoricalQuoteSpreadService::class)->attach($this->rows(), $this->sourceHash);
        $this->assertSame('unavailable', $result['provenance']['status']);
        $this->assertArrayNotHasKey('spread', $result['rows'][0]);
        $this->artifact();
        $result = app(HistoricalQuoteSpreadService::class)->attach($this->rows(), str_repeat('b', 64));
        $this->assertSame('unavailable', $result['provenance']['status']);
    }

    public function test_sidecar_byte_tampering_fails_closed(): void
    {
        $directory = $this->artifact();
        File::append($directory.'/m5-spread.csv', "tampered\n");
        $this->expectExceptionMessage('HISTORICAL_QUOTE_ARTIFACT_INVALID');
        app(HistoricalQuoteSpreadService::class)->attach($this->rows(), $this->sourceHash);
    }

    public function test_quote_at_close_cannot_be_used_for_the_already_closing_candle(): void
    {
        $this->artifact([], '2025-12-22T13:10:00.000Z', 0);
        $this->expectExceptionMessage('HISTORICAL_QUOTE_ALIGNMENT_INVALID');
        app(HistoricalQuoteSpreadService::class)->attach($this->rows(), $this->sourceHash);
    }

    public function test_a_stale_quote_is_not_liquidity_evidence(): void
    {
        $this->artifact([], '2025-12-22T13:08:00.000Z', 120000);
        $this->expectExceptionMessage('HISTORICAL_QUOTE_ALIGNMENT_INVALID');
        app(HistoricalQuoteSpreadService::class)->attach($this->rows(), $this->sourceHash);
    }

    public function test_frozen_bid_mismatch_is_rejected_even_with_rehashed_artifact(): void
    {
        $this->artifact();
        $rows = $this->rows();
        $rows[0]['close'] = 100.1;
        $this->expectExceptionMessage('HISTORICAL_QUOTE_FROZEN_BID_MISMATCH');
        app(HistoricalQuoteSpreadService::class)->attach($rows, $this->sourceHash);
    }

    public function test_agreeing_overlap_keeps_both_immutable_sources(): void
    {
        $this->artifact();
        $this->artifact(['from_utc' => '2025-12-21']);
        $result = app(HistoricalQuoteSpreadService::class)->attach($this->rows(), $this->sourceHash);
        $this->assertCount(2, $result['provenance']['sources']);
        $this->assertSame(1, $result['provenance']['available_rows']);
    }

    public function test_conflicting_overlap_cannot_silently_replace_a_frozen_quote(): void
    {
        $this->artifact();
        $this->artifact(['from_utc' => '2025-12-21'], '2025-12-22T13:09:58.000Z', 2000);
        $this->expectExceptionMessage('HISTORICAL_QUOTE_OVERLAP_CONFLICT');
        app(HistoricalQuoteSpreadService::class)->attach($this->rows(), $this->sourceHash);
    }

    public function test_rehashed_paper_interval_has_no_research_authority(): void
    {
        $this->artifact(['to_utc_exclusive' => '2026-01-02']);
        $this->expectExceptionMessage('HISTORICAL_QUOTE_INTERVAL_INVALID');
        app(HistoricalQuoteSpreadService::class)->attach($this->rows(), $this->sourceHash);
    }

    public function test_missing_decoder_identity_is_not_provenance(): void
    {
        $this->artifact(['decoder_sha256' => '']);
        $this->expectExceptionMessage('HISTORICAL_QUOTE_SOURCE_INVALID');
        app(HistoricalQuoteSpreadService::class)->attach($this->rows(), $this->sourceHash);
    }

    private function rows(): array
    {
        return [['time' => '2025-12-22 13:05:00', 'close' => 100.0], ['time' => '2025-12-22 13:10:00', 'close' => 100.0]];
    }

    private function artifact(array $overrides = [], string $quoteTime = '2025-12-22T13:09:59.000Z', int $age = 1000): string
    {
        $csv = "time,spread,spread_available,bid_close,ask_close,quote_time_utc,available_after_utc,quote_age_ms,observation_status\n"
            ."2025-12-22 13:05:00,0.5,1,100,100.5,{$quoteTime},2025-12-22T13:10:00.000Z,{$age},paired\n";
        $identity = [...[
            'protocol' => 'research_quote_spread_ticks_v1', 'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'm5_sha256' => $this->sourceHash, 'provider' => 'dukascopy_historical_synchronized_tick_v1',
            'observation' => 'last_synchronized_bid_ask_tick_strictly_before_m5_close', 'maximum_quote_age_ms' => 60000,
            'source_hour_content_sha256' => ['2025-12-22T13:00:00.000Z' => str_repeat('c', 64)],
            'from_utc' => '2025-12-22', 'freezer_sha256' => str_repeat('d', 64),
            'decoder_sha256' => str_repeat('e', 64), 'dependency_lock_sha256' => str_repeat('f', 64),
            'to_utc_exclusive' => '2025-12-23', 'sidecar_sha256' => hash('sha256', $csv),
            'paper_2026_included' => false, 'promotion_evidence' => false, 'automatic_replay_authority' => false,
        ], ...$overrides];
        $hash = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $directory = storage_path('app/lab-datasets/quote-spread/'.$hash);
        File::ensureDirectoryExists($directory);
        File::put($directory.'/m5-spread.csv', $csv);
        File::put($directory.'/manifest.json', json_encode(['identity_hash' => $hash, 'identity' => $identity]));

        return $directory;
    }
}

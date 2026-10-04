<?php

namespace Tests\Feature;

use App\Models\MarketTrainingArchive;
use App\Services\LabDatasetExportService;
use App\Services\MarketData\HistoricalQuoteSpreadService;
use App\Services\MarketData\MarketTrainingDataService;
use App\Services\MultiTimeframeSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MtfAgentValidationSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public static function quoteModes(): array
    {
        return ['price_and_volume_only' => [false], 'prospective_observed_quote' => [true]];
    }

    #[DataProvider('quoteModes')]
    public function test_agent_validation_freezes_bounded_training_store_without_live_export(bool $withQuote): void
    {
        $m5 = $this->rows('2020-01-01 00:00:00', 5, MultiTimeframeSnapshotService::AGENT_VALIDATION_MIN_M5_ROWS);
        $m15 = $this->rows('2019-09-01 00:00:00', 15, 20000);
        $h1 = $this->rows('2019-09-01 00:00:00', 60, 5000);
        $entryLast = CarbonImmutable::parse($m5[array_key_last($m5)]['time'], 'UTC');
        foreach ([
            ['foundation_intraday_10y', 'M5', count($m5), $m5[0]['time'], $entryLast, 'backfilling'],
            ['foundation_10y', 'M15', count($m15), $m15[0]['time'], '2025-12-31 23:45:00', 'complete'],
            ['foundation_10y', 'H1', count($h1), $h1[0]['time'], '2025-12-31 23:00:00', 'complete'],
        ] as [$dataset, $timeframe, $count, $first, $last, $status]) {
            MarketTrainingArchive::query()->create([
                'dataset_key' => $dataset,
                'provider' => 'dukascopy',
                'symbol' => 'XAUUSD',
                'timeframe' => $timeframe,
                'target_from' => $first,
                'target_to' => '2026-01-01 00:00:00',
                'backfill_cursor_at' => $last,
                'status' => $status,
                'row_count' => $count,
                'first_candle_at' => $first,
                'last_candle_at' => $last,
            ]);
        }

        $datasets = m::mock(LabDatasetExportService::class);
        $datasets->shouldNotReceive('export');
        $datasets->shouldNotReceive('exportPaper');
        $training = m::mock(MarketTrainingDataService::class);
        $training->shouldReceive('candlesForAgent')->once()->withArgs(
            fn ($dataset, $provider, $symbol, $timeframe, $from, $to, $limit): bool => $dataset === 'foundation_intraday_10y'
                && $provider === 'dukascopy' && $symbol === 'XAUUSD' && $timeframe === 'M5'
                && $from === null && $limit === MultiTimeframeSnapshotService::AGENT_VALIDATION_MAX_M5_ROWS,
        )->andReturn($m5);
        $training->shouldReceive('candlesForAgent')->once()->withArgs(
            fn ($dataset, $provider, $symbol, $timeframe): bool => $dataset === 'foundation_10y'
                && $provider === 'dukascopy' && $symbol === 'XAUUSD' && $timeframe === 'M15',
        )->andReturn($m15);
        $training->shouldReceive('candlesForAgent')->once()->withArgs(
            fn ($dataset, $provider, $symbol, $timeframe): bool => $dataset === 'foundation_10y'
                && $provider === 'dukascopy' && $symbol === 'XAUUSD' && $timeframe === 'H1',
        )->andReturn($h1);
        if ($withQuote) {
            $quotes = m::mock(HistoricalQuoteSpreadService::class);
            $quotes->shouldReceive('attach')->once()->withArgs(function (array $rows, string $hash): bool {
                $this->assertTrue($rows[0]['volume_available']);
                // Quote matching sees exactly the pre-attachment canonical CSV,
                // including the audited volume marker, not the enriched file.
                $stream = fopen('php://temp', 'w+b');
                fputcsv($stream, ['time', 'open', 'high', 'low', 'close', 'volume', 'volume_available']);
                foreach ($rows as $row) {
                    fputcsv($stream, [$row['time'], $row['open'], $row['high'], $row['low'], $row['close'], $row['volume'], 1]);
                }
                rewind($stream);
                $digest = hash_init('sha256');
                hash_update_stream($digest, $stream);
                fclose($stream);
                $this->assertSame(hash_final($digest), $hash);

                return true;
            })->andReturnUsing(function (array $rows, string $hash): array {
                foreach ($rows as $index => &$row) {
                    $ready = $index === 0;
                    $close = CarbonImmutable::parse($row['time'], 'UTC')->addMinutes(5);
                    $row = [...$row, 'spread_available' => $ready, 'spread' => $ready ? 0.5 : null,
                        'bid_close' => $ready ? $row['close'] : null, 'ask_close' => $ready ? $row['close'] + 0.5 : null,
                        'quote_time_utc' => $ready ? $close->subSecond()->toIso8601String() : null,
                        'quote_available_after_utc' => $ready ? $close->toIso8601String() : null,
                        'quote_age_ms' => $ready ? 1000 : null];
                }
                unset($row);

                return ['rows' => $rows, 'provenance' => ['protocol' => HistoricalQuoteSpreadService::PROTOCOL,
                    'status' => 'partial', 'source_m5_csv_sha256' => $hash, 'available_rows' => 1,
                    'promotion_evidence' => false, 'execution_cost_model_changed' => false]];
            });
            $this->app->instance(HistoricalQuoteSpreadService::class, $quotes);
        }
        $service = new MultiTimeframeSnapshotService($datasets, $training);

        $this->assertTrue($service->agentValidationReadiness('XAUUSD')['ready']);
        $bundle = $service->forAgentOwnedConfirmationValidation('XAUUSD');
        $restored = $service->restoreAgentOwnedConfirmationValidationBundle((array) $bundle['manifest']);

        try {
            $this->assertSame('agent_owned_mtf_foundation_bundle_v1', data_get($bundle, 'manifest.validation_bundle_protocol'));
            $this->assertSame('pre_2026_foundation_training_only', data_get($bundle, 'manifest.data_role'));
            $this->assertSame(count($m5), data_get($bundle, 'manifest.streams.M5.row_count'));
            $this->assertSame('passed', data_get($bundle, 'manifest.volume_provenance.status'));
            $this->assertFalse((bool) data_get($bundle, 'manifest.volume_provenance.live_coverage_inherited'));
            $this->assertSame('passed', data_get($bundle, 'manifest.streams.M5.volume_quality.status'));
            $entryHeader = str_getcsv((string) strtok((string) File::get((string) $bundle['entry_dataset_path']), "\n"));
            $this->assertContains('volume_available', $entryHeader);
            if ($withQuote) {
                $this->assertContains('spread_available', $entryHeader);
                $this->assertContains('quote_available_after_utc', $entryHeader);
                $handle = fopen((string) $bundle['entry_dataset_path'], 'rb');
                $headers = fgetcsv($handle);
                $first = array_combine($headers, fgetcsv($handle));
                $second = array_combine($headers, fgetcsv($handle));
                fclose($handle);
                $this->assertSame('1', $first['spread_available']);
                $this->assertSame('0.5', $first['spread']);
                $this->assertSame('0', $second['spread_available']);
                $this->assertSame('', $second['spread']);
                $this->assertSame('partial', data_get($bundle, 'manifest.quote_spread_provenance.status'));
                $this->assertFalse(data_get($bundle, 'manifest.quote_spread_provenance.promotion_evidence'));
                $this->assertNotSame(data_get($bundle, 'manifest.quote_spread_provenance.source_m5_csv_sha256'), data_get($bundle, 'manifest.streams.M5.sha256'));
            } else {
                $this->assertNotContains('spread_available', $entryHeader);
                $this->assertSame('unavailable', data_get($bundle, 'manifest.quote_spread_provenance.status'));
            }
            $this->assertTrue((bool) data_get($bundle, 'manifest.bounded_cost_contract.full_live_export_forbidden'));
            $this->assertTrue((bool) data_get($bundle, 'manifest.post_selection_historical_evidence'));
            $this->assertFalse((bool) data_get($bundle, 'manifest.promotion_evidence'));
            $this->assertFileExists((string) $bundle['entry_dataset_path']);
            $this->assertFileExists((string) data_get($bundle, 'context_dataset_paths.H4'));
            $this->assertTrue((bool) data_get($restored, 'restored_from_sealed_retry'));
            $this->assertSame($bundle['bundle_hash'], $restored['bundle_hash']);
            $this->assertSame($bundle['entry_dataset_path'], $restored['entry_dataset_path']);
        } finally {
            File::deleteDirectory(dirname((string) $bundle['manifest_path']));
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(string $start, int $minutes, int $count): array
    {
        $cursor = CarbonImmutable::parse($start, 'UTC');
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $price = 1800.0 + ($index * 0.0001);
            $rows[] = [
                'time' => $cursor->addMinutes($index * $minutes)->format('Y-m-d H:i:s'),
                'open' => $price,
                'high' => $price + 0.5,
                'low' => $price - 0.5,
                'close' => $price + 0.1,
                'volume' => 10.0,
            ];
        }

        return $rows;
    }
}

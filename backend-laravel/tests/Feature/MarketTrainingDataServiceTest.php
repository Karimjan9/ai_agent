<?php

namespace Tests\Feature;

use App\Models\MarketTrainingCandle;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MarketData\MarketTrainingDataService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MarketTrainingDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_import_is_idempotent_and_keeps_training_data_out_of_canonical_candles(): void
    {
        $training = app(MarketTrainingDataService::class);
        $archive = $training->ensureArchive(
            'foundation_10y',
            'dukascopy',
            'XAUUSD',
            'H1',
            CarbonImmutable::parse('2016-08-13 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-08-13 00:00:00', 'UTC'),
        );
        $path = storage_path('app/testing/xauusd-training.csv');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode(PHP_EOL, [
            'time,open,high,low,close,volume',
            '2020-01-02 01:00:00,1550.1,1552.0,1549.0,1551.2,0.5',
            '2020-01-02 02:00:00,1551.2,1554.0,1550.0,1553.4,0.7',
        ]).PHP_EOL);

        try {
            $first = $training->importCsv($archive, $path);
            $second = $training->importCsv($archive, $path);

            $this->assertSame(2, $first['imported']);
            $this->assertSame(2, $second['imported']);
            $this->assertSame(2, MarketTrainingCandle::query()->count());
            $this->assertSame(2, (int) $archive->fresh()->row_count);
            $this->assertDatabaseCount('candles', 0);
            $this->assertSame('2020-01-02 01:00:00', $training->candlesForAgent(
                'foundation_10y',
                'dukascopy',
                'XAUUSD',
                'H1',
            )[0]['time']);
        } finally {
            File::delete($path);
        }
    }

    public function test_invalid_ohlc_rows_are_not_written(): void
    {
        $training = app(MarketTrainingDataService::class);

        $saved = $training->upsertCandles('foundation_10y', 'dukascopy', 'XAUUSD', 'M15', [
            [
                'time' => '2020-01-02 01:00:00',
                'open' => 1550,
                'high' => 1540,
                'low' => 1555,
                'close' => 1551,
                'volume' => 1,
            ],
        ]);

        $this->assertSame(0, $saved);
        $this->assertDatabaseCount('market_training_candles', 0);
    }

    public function test_training_lane_rejects_paper_year_candles(): void
    {
        $this->expectExceptionMessage('2026 faqat paper lane');

        app(MarketTrainingDataService::class)->upsertCandles('foundation_10y', 'dukascopy', 'XAUUSD', 'H1', [[
            'time' => '2026-01-01 00:00:00',
            'open' => 1550,
            'high' => 1552,
            'low' => 1549,
            'close' => 1551,
            'volume' => 1,
        ]]);
    }

    public function test_agent_payload_is_explicitly_read_from_the_training_lane(): void
    {
        $training = app(MarketTrainingDataService::class);
        $training->upsertCandles('foundation_10y', 'dukascopy', 'XAUUSD', 'M15', [[
            'time' => '2020-01-02 01:00:00',
            'open' => 1550,
            'high' => 1552,
            'low' => 1549,
            'close' => 1551,
            'volume' => 1,
        ]]);

        $payload = app(CandlePayloadService::class)->candlesForTraining(
            'XAUUSD',
            'M15',
            'foundation_10y',
            'dukascopy',
        );

        $this->assertCount(1, $payload);
        $this->assertSame('2020-01-02 01:00:00', $payload[0]['time']);
        $this->assertDatabaseCount('candles', 0);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MarketHealthSample;
use App\Models\PaperSignal;
use App\Models\Symbol;
use App\Services\LabDatasetExportService;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\PaperEvidenceReadinessService;
use App\Services\PhaseTwoFoundationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use LogicException;
use Tests\TestCase;
use App\Jobs\EvaluateLabAgentJob;

class P0EvidenceControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_dataset_export_has_exact_row_count_and_sha256_manifest(): void
    {
        config()->set('services.historical_data.minimum_rows', 500);
        $symbol = Symbol::create(['code' => 'EURUSD', 'display_name' => 'EUR/USD', 'asset_class' => 'forex', 'is_active' => true]);
        $this->insertCandles($symbol, 500);

        $path = app(LabDatasetExportService::class)->export('EURUSD', 'H1');
        $manifest = json_decode((string) File::get($path.'.manifest.json'), true);

        $this->assertSame(500, $manifest['row_count']);
        $this->assertSame(hash_file('sha256', $path), $manifest['sha256']);
        $this->assertSame('ready', $manifest['status']);
        $this->assertCount(501, file($path));

        File::delete([$path, $path.'.manifest.json']);
    }

    public function test_full_replay_coverage_is_stricter_than_recent_screening_readiness(): void
    {
        config()->set('services.historical_data.minimum_rows', 500);
        $symbol = Symbol::create(['code' => 'EURUSD', 'display_name' => 'EUR/USD', 'asset_class' => 'forex', 'is_active' => true]);
        $this->insertCandles($symbol, 500);

        $screening = app(HistoricalDataQualityService::class)->inspect('EURUSD', 'H1', true);
        $full = app(HistoricalDataQualityService::class)->fullReplayCoverage('EURUSD', 'H1');

        $this->assertSame('ready', $screening['status']);
        $this->assertSame('blocked', $full['status']);
        $this->assertSame('database', $full['source']);
        $this->assertContains('FOUNDATION_HISTORY_BEFORE_2005_01_02_MARKET_OPEN_REQUIRED', $full['reasons']);
        $this->assertFalse($full['promotion_evidence']);
    }

    public function test_full_replay_accepts_a_sealed_foundation_archive_with_a_recent_rolling_snapshot(): void
    {
        $rolling = [
            'row_count' => 5999,
            'first_candle_at' => '2025-11-27 06:00:00',
            'last_candle_at' => '2026-08-10 05:00:00',
        ];
        $foundation = [
            'row_count' => 10000,
            'first_candle_at' => '2005-01-02 23:00:00',
            'last_candle_at' => '2025-12-31 22:00:00',
            'continuity' => [
                'protocol' => HistoricalDataQualityService::FOUNDATION_CONTINUITY_PROTOCOL,
                'status' => 'ready',
                'row_count' => 10000,
                'unexpected_gap_count' => 0,
                'missing_open_candles' => 0,
                'invalid_rows' => 0,
            ],
        ];

        $coverage = app(HistoricalDataQualityService::class)->fullReplayCoverage(
            'XAUUSD',
            'H1',
            $rolling,
            $foundation,
        );

        $this->assertSame('ready', $coverage['status']);
        $this->assertSame('generation_snapshots', $coverage['source']);
        $this->assertTrue($coverage['separate_sources']);
        $this->assertFalse($coverage['promotion_evidence']);
    }

    public function test_foundation_csv_continuity_passport_rejects_unexpected_weekday_gap(): void
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = tempnam($directory, 'foundation-continuity-');
        $handle = fopen($path, 'wb');
        fputcsv($handle, ['time', 'open', 'high', 'low', 'close', 'volume']);
        fputcsv($handle, ['2026-07-20 10:00:00', 1.1, 1.2, 1.0, 1.1, 1]);
        fputcsv($handle, ['2026-07-20 12:00:00', 1.1, 1.2, 1.0, 1.1, 1]);
        fclose($handle);

        $passport = app(HistoricalDataQualityService::class)->inspectCsvContinuity($path, 'EURUSD', 'H1');

        $this->assertSame(HistoricalDataQualityService::FOUNDATION_CONTINUITY_PROTOCOL, $passport['protocol']);
        $this->assertSame('blocked', $passport['status']);
        $this->assertSame(1, $passport['unexpected_gap_count']);
        $this->assertSame(1, $passport['missing_open_candles']);
        $this->assertContains('FOUNDATION_DATASET_CONTINUITY_GAPS', $passport['reasons']);

        File::delete($path);
    }

    public function test_full_replay_mutex_uses_the_shared_recovery_key(): void
    {
        $job = new EvaluateLabAgentJob(1, 'XAUUSD', 'full');
        $mutex = collect($job->middleware())->first(fn (object $middleware): bool => $middleware instanceof WithoutOverlapping);

        $this->assertInstanceOf(WithoutOverlapping::class, $mutex);
        $this->assertSame('neurotrader-ai-heavy-replay', config('services.lab_queue.replay_mutex_key'));
        $this->assertSame(
            'laravel-queue-overlap:'.config('services.lab_queue.replay_mutex_key'),
            $mutex->getLockKey($job),
        );
    }

    public function test_weekday_gap_is_a_hard_gate(): void
    {
        config()->set('services.historical_data.minimum_rows', 500);
        $symbol = Symbol::create(['code' => 'GBPUSD', 'display_name' => 'GBP/USD', 'asset_class' => 'forex', 'is_active' => true]);
        $this->insertCandles($symbol, 500, 250);

        $report = app(HistoricalDataQualityService::class)->inspect('GBPUSD', 'H1', true);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame(1, $report['missing_open_hours']);
    }

    public function test_m15_weekday_gap_is_a_hard_gate(): void
    {
        config()->set('services.historical_data.minimum_rows', 2);
        $symbol = Symbol::create(['code' => 'EURUSD', 'display_name' => 'EUR/USD', 'asset_class' => 'forex', 'is_active' => true]);
        foreach (['2026-07-20 10:00:00', '2026-07-20 10:30:00'] as $time) {
            Candle::create([
                'symbol_id' => $symbol->id, 'timeframe' => 'M15', 'time' => $time,
                'open' => 1.1, 'high' => 1.2, 'low' => 1.0, 'close' => 1.1, 'volume' => 1,
            ]);
        }

        $report = app(HistoricalDataQualityService::class)->inspect('EURUSD', 'M15', true);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame(1, $report['missing_open_candles']);
    }

    public function test_new_year_archive_closure_is_not_counted_as_a_data_gap(): void
    {
        $symbol = Symbol::create(['code' => 'EURUSD', 'display_name' => 'EUR/USD', 'asset_class' => 'forex', 'is_active' => true]);
        foreach (['2019-12-31 21:00:00', '2020-01-01 23:00:00'] as $time) {
            Candle::create([
                'symbol_id' => $symbol->id, 'timeframe' => 'H1', 'time' => $time,
                'open' => 1.1, 'high' => 1.2, 'low' => 1.0, 'close' => 1.1, 'volume' => 1,
            ]);
        }

        $report = app(HistoricalDataQualityService::class)->inspect('EURUSD', 'H1', true);

        $this->assertSame(0, $report['missing_open_hours']);
    }

    public function test_fx_christmas_eve_archive_closure_is_not_counted_as_a_data_gap(): void
    {
        $symbol = Symbol::create(['code' => 'EURUSD', 'display_name' => 'EUR/USD', 'asset_class' => 'forex', 'is_active' => true]);
        foreach (['2025-12-24 12:00:00', '2025-12-25 13:00:00'] as $time) {
            Candle::create([
                'symbol_id' => $symbol->id, 'timeframe' => 'H1', 'time' => $time,
                'open' => 1.1, 'high' => 1.2, 'low' => 1.0, 'close' => 1.1, 'volume' => 1,
            ]);
        }

        $report = app(HistoricalDataQualityService::class)->inspect('EURUSD', 'H1', true);

        $this->assertSame(0, $report['missing_open_hours']);
    }

    public function test_xau_holiday_weekend_is_not_counted_as_a_data_gap(): void
    {
        $symbol = Symbol::create(['code' => 'XAUUSD', 'display_name' => 'Gold', 'asset_class' => 'metal', 'is_active' => true]);
        foreach (['2005-02-18 18:00:00', '2005-02-22 09:00:00'] as $time) {
            Candle::create([
                'symbol_id' => $symbol->id, 'timeframe' => 'H1', 'time' => $time,
                'open' => 430, 'high' => 431, 'low' => 429, 'close' => 430, 'volume' => 1,
            ]);
        }

        $report = app(HistoricalDataQualityService::class)->inspect('XAUUSD', 'H1', true);

        $this->assertSame(0, $report['missing_open_hours']);
    }

    public function test_xau_maundy_thursday_closure_is_not_counted_as_a_data_gap(): void
    {
        $symbol = Symbol::create(['code' => 'XAUUSD', 'display_name' => 'Gold', 'asset_class' => 'metal', 'is_active' => true]);
        foreach (['2011-04-20 21:00:00', '2011-04-21 08:00:00'] as $time) {
            Candle::create([
                'symbol_id' => $symbol->id, 'timeframe' => 'H1', 'time' => $time,
                'open' => 1485, 'high' => 1486, 'low' => 1484, 'close' => 1485, 'volume' => 1,
            ]);
        }

        $report = app(HistoricalDataQualityService::class)->inspect('XAUUSD', 'H1', true);

        $this->assertSame(0, $report['missing_open_hours']);
    }

    public function test_xau_new_york_maintenance_hour_is_not_counted_as_a_data_gap(): void
    {
        $symbol = Symbol::create(['code' => 'XAUUSD', 'display_name' => 'Gold', 'asset_class' => 'metal', 'is_active' => true]);
        foreach (['2025-07-15 20:00:00', '2025-07-15 22:00:00'] as $time) {
            Candle::create([
                'symbol_id' => $symbol->id, 'timeframe' => 'H1', 'time' => $time,
                'open' => 3300, 'high' => 3301, 'low' => 3299, 'close' => 3300, 'volume' => 1,
            ]);
        }

        $report = app(HistoricalDataQualityService::class)->inspect('XAUUSD', 'H1', true);

        $this->assertSame(0, $report['missing_open_hours']);
    }

    public function test_xau_new_years_eve_early_close_is_not_counted_as_a_data_gap(): void
    {
        $symbol = Symbol::create(['code' => 'XAUUSD', 'display_name' => 'Gold', 'asset_class' => 'metal', 'is_active' => true]);
        foreach (['2004-12-31 16:00:00', '2004-12-31 19:00:00'] as $time) {
            Candle::create([
                'symbol_id' => $symbol->id, 'timeframe' => 'H1', 'time' => $time,
                'open' => 438, 'high' => 439, 'low' => 437, 'close' => 438, 'volume' => 1,
            ]);
        }

        $report = app(HistoricalDataQualityService::class)->inspect('XAUUSD', 'H1', true);

        $this->assertSame(0, $report['missing_open_hours']);
    }

    public function test_paper_signal_and_outcome_evidence_starts_blocked_and_signal_is_immutable(): void
    {
        $model = ModelVersion::create([
            'name' => 'p0_model', 'strategy' => 'p0_model', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'trend', 'status' => 'forward_validated', 'paper_status' => 'pending',
        ]);
        $signal = PaperSignal::create([
            'model_market_performance_id' => $performance->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'candle_time' => now()->startOfHour(),
            'decision' => 'WAIT', 'price' => 3300, 'confidence' => 70,
            'payload' => ['signal' => 'WAIT'], 'payload_hash' => hash('sha256', 'wait'),
        ]);
        $readiness = app(PaperEvidenceReadinessService::class)->inspect();
        $this->assertFalse($readiness['ready']);
        $this->assertSame(1, $readiness['metrics']['signal_count']);

        $this->expectException(LogicException::class);
        $signal->update(['decision' => 'BUY']);
    }

    public function test_missing_signal_is_a_warning_until_a_valid_paper_candidate_exists(): void
    {
        $check = app(PhaseTwoFoundationService::class)->runHealthCheck()->firstWhere('service_key', 'signal_foundation');

        $this->assertSame('warning', $check->status);
        $this->assertStringContainsString('No valid paper-eligible candidate', $check->message);
    }

    public function test_paper_feed_uptime_uses_only_the_active_provider(): void
    {
        config()->set('services.mt5.provider', 'twelve');
        $model = ModelVersion::create([
            'name' => 'uptime_model', 'strategy' => 'uptime_model', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'trend', 'status' => 'forward_validated', 'paper_status' => 'pending',
        ]);
        PaperSignal::unguarded(fn () => PaperSignal::create([
            'model_market_performance_id' => $performance->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'candle_time' => now()->startOfHour(),
            'decision' => 'WAIT', 'price' => 3300, 'confidence' => 70,
            'payload' => ['signal' => 'WAIT'], 'payload_hash' => hash('sha256', 'uptime-wait'),
            'created_at' => now()->subMinutes(10),
        ]));
        $firstSignalAt = CarbonImmutable::parse((string) PaperSignal::min('created_at'));
        $expectedSamples = max(1, (int) ceil($firstSignalAt->diffInSeconds(now()) / 60) + 1);
        for ($sample = 0; $sample < $expectedSamples; $sample++) {
            MarketHealthSample::create([
                'provider' => 'twelve', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'status' => 'ok',
                'age_seconds' => 0, 'sampled_at' => now(),
            ]);
        }
        MarketHealthSample::create([
            'provider' => 'dukascopy', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'status' => 'lost',
            'age_seconds' => 9999, 'sampled_at' => now(),
        ]);

        $readiness = app(PaperEvidenceReadinessService::class)->inspect();

        $this->assertSame(100.0, $readiness['metrics']['feed_uptime_percent']);
    }

    private function insertCandles(Symbol $symbol, int $count, ?int $gapAt = null): void
    {
        $start = CarbonImmutable::parse('2025-01-06 00:00:00', 'UTC');
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $offset = $index + ($gapAt !== null && $index >= $gapAt ? 1 : 0);
            $rows[] = [
                'symbol_id' => $symbol->id, 'timeframe' => 'H1', 'time' => $start->addHours($offset),
                'open' => 1.1, 'high' => 1.2, 'low' => 1.0, 'close' => 1.1, 'volume' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) Candle::insert($chunk);
    }
}

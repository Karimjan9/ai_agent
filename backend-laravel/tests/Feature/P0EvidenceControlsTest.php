<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\PaperSignal;
use App\Models\Symbol;
use App\Services\LabDatasetExportService;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\PaperEvidenceReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use LogicException;
use Tests\TestCase;

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

    public function test_weekday_gap_is_a_hard_gate(): void
    {
        config()->set('services.historical_data.minimum_rows', 500);
        $symbol = Symbol::create(['code' => 'GBPUSD', 'display_name' => 'GBP/USD', 'asset_class' => 'forex', 'is_active' => true]);
        $this->insertCandles($symbol, 500, 250);

        $report = app(HistoricalDataQualityService::class)->inspect('GBPUSD', 'H1', true);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame(1, $report['missing_open_hours']);
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

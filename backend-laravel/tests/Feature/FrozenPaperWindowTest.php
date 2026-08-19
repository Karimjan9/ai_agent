<?php

namespace Tests\Feature;

use App\Models\FrozenPaperWindow;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MarketData\FrozenPaperWindowService;
use App\Services\MarketData\MarketTrainingDataService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrozenPaperWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seals_the_last_six_months_and_excludes_them_from_training_payloads(): void
    {
        $training = app(MarketTrainingDataService::class);
        $training->upsertCandles('foundation_10y', 'dukascopy', 'XAUUSD', 'H1', [
            $this->candle('2016-01-04 00:00:00'),
            $this->candle('2026-02-18 23:00:00'),
            $this->candle('2026-02-19 00:00:00'),
            $this->candle('2026-08-18 23:00:00'),
        ]);

        $window = app(FrozenPaperWindowService::class)->freeze(
            'foundation_10y', 'dukascopy', 'XAUUSD', 'H1',
            CarbonImmutable::parse('2026-08-19 00:00:00', 'UTC'),
        );

        $this->assertSame('2026-02-19 00:00:00', $window->training_ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-19 00:00:00', $window->paper_starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(2, $window->row_count);
        $this->assertFileExists($window->snapshot_path);

        $trainingRows = app(CandlePayloadService::class)->candlesForTraining('XAUUSD', 'H1');
        $this->assertCount(2, $trainingRows);
        $this->assertSame('2026-02-18 23:00:00', $trainingRows[1]['time']);
        $this->assertDatabaseCount('frozen_paper_windows', 1);
        File::delete($window->snapshot_path);
    }

    public function test_existing_window_is_not_moved_when_time_passes(): void
    {
        $training = app(MarketTrainingDataService::class);
        $training->upsertCandles('foundation_10y', 'dukascopy', 'XAUUSD', 'H1', [
            $this->candle('2016-01-04 00:00:00'),
            $this->candle('2026-02-19 00:00:00'),
            $this->candle('2026-08-18 23:00:00'),
            $this->candle('2026-10-18 23:00:00'),
        ]);
        $service = app(FrozenPaperWindowService::class);
        $first = $service->freeze('foundation_10y', 'dukascopy', 'XAUUSD', 'H1', CarbonImmutable::parse('2026-08-19 00:00:00', 'UTC'));
        $second = $service->freeze('foundation_10y', 'dukascopy', 'XAUUSD', 'H1', CarbonImmutable::parse('2026-10-19 00:00:00', 'UTC'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('2026-08-19 00:00:00', $second->paper_ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, FrozenPaperWindow::query()->count());
        File::delete($first->snapshot_path);
    }

    /** @return array<string, float|string> */
    private function candle(string $time): array
    {
        return ['time' => $time, 'open' => 2000, 'high' => 2002, 'low' => 1998, 'close' => 2001, 'volume' => 1];
    }
}

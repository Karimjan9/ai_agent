<?php

namespace Tests\Feature;

use App\Models\FrozenPaperWindow;
use App\Models\Candle;
use App\Models\Symbol;
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
        ]);
        $this->paperCandles(['2026-02-18 23:00:00', '2026-02-19 00:00:00', '2026-08-18 23:00:00']);

        $window = app(FrozenPaperWindowService::class)->freeze(
            'foundation_10y', 'dukascopy', 'XAUUSD', 'H1',
            CarbonImmutable::parse('2026-08-19 00:00:00', 'UTC'),
        );

        $this->assertSame('2026-02-19 00:00:00', $window->training_ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-19 00:00:00', $window->paper_starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(2, $window->row_count);
        $this->assertFileExists($window->snapshot_path);

        $trainingRows = app(CandlePayloadService::class)->candlesForTraining('XAUUSD', 'H1');
        $this->assertCount(1, $trainingRows);
        $this->assertSame('2016-01-04 00:00:00', $trainingRows[0]['time']);
        $this->assertDatabaseCount('frozen_paper_windows', 1);
        File::delete($window->snapshot_path);
    }

    public function test_existing_window_is_not_moved_when_time_passes(): void
    {
        $training = app(MarketTrainingDataService::class);
        $training->upsertCandles('foundation_10y', 'dukascopy', 'XAUUSD', 'H1', [
            $this->candle('2016-01-04 00:00:00'),
        ]);
        $this->paperCandles(['2026-02-19 00:00:00', '2026-08-18 23:00:00', '2026-10-18 23:00:00']);
        $service = app(FrozenPaperWindowService::class);
        $first = $service->freeze('foundation_10y', 'dukascopy', 'XAUUSD', 'H1', CarbonImmutable::parse('2026-08-19 00:00:00', 'UTC'));
        $second = $service->freeze('foundation_10y', 'dukascopy', 'XAUUSD', 'H1', CarbonImmutable::parse('2026-10-19 00:00:00', 'UTC'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('2026-08-19 00:00:00', $second->paper_ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, FrozenPaperWindow::query()->count());
        File::delete($first->snapshot_path);
    }

    public function test_annual_paper_policy_can_reserve_all_of_2026_and_moves_training_cutoff_to_january(): void
    {
        $training = app(MarketTrainingDataService::class);
        $training->upsertCandles('foundation_10y', 'dukascopy', 'XAUUSD', 'H1', [
            $this->candle('2016-01-04 00:00:00'),
            $this->candle('2025-12-31 23:00:00'),
        ]);
        $this->paperCandles(['2026-01-01 00:00:00', '2026-06-18 23:00:00']);

        $annual = app(FrozenPaperWindowService::class)->freeze(
            'foundation_10y', 'dukascopy', 'XAUUSD', 'H1',
            CarbonImmutable::parse('2026-06-19 00:00:00', 'UTC'),
            12,
            CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
            'paper_2026',
        );

        $this->assertSame('paper_2026', $annual->window_key);
        $this->assertSame('2026-01-01 00:00:00', $annual->training_ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-01 00:00:00', $annual->paper_starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(2, $annual->row_count);
        $this->assertSame($annual->id, app(FrozenPaperWindowService::class)
            ->active('foundation_10y', 'dukascopy', 'XAUUSD', 'H1')?->id);
        $trainingRows = app(CandlePayloadService::class)->candlesForTraining('XAUUSD', 'H1');
        $this->assertCount(2, $trainingRows);
        $this->assertStringStartsWith('2025-', $trainingRows[1]['time']);
        File::delete($annual->snapshot_path);
    }

    /** @return array<string, float|string> */
    private function candle(string $time): array
    {
        return ['time' => $time, 'open' => 2000, 'high' => 2002, 'low' => 1998, 'close' => 2001, 'volume' => 1];
    }

    /** @param array<int, string> $times */
    private function paperCandles(array $times): void
    {
        $symbol = Symbol::query()->firstOrCreate(
            ['code' => 'XAUUSD'],
            ['display_name' => 'Gold', 'asset_class' => 'metal', 'is_active' => true],
        );
        foreach ($times as $time) {
            Candle::query()->create([
                'symbol_id' => $symbol->id,
                'timeframe' => 'H1',
                ...$this->candle($time),
            ]);
        }
    }
}

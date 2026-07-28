<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\MarketDataSyncState;
use App\Models\Symbol;
use App\Services\MarketData\MarketDataContinuityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketDataContinuityTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_failure_persists_a_retryable_pending_interval(): void
    {
        app(MarketDataContinuityService::class)->recordFailure(
            'dukascopy',
            'EURUSD',
            'H1',
            CarbonImmutable::parse('2026-07-13 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-07-13 03:00:00', 'UTC'),
            'Network connection failed.',
        );

        $this->assertDatabaseHas('market_data_sync_states', [
            'provider' => 'dukascopy',
            'symbol' => 'EURUSD',
            'timeframe' => 'H1',
            'status' => 'offline',
            'retry_count' => 1,
        ]);
    }

    public function test_complete_recovery_marks_sync_healthy_but_open_hour_gap_stays_pending(): void
    {
        $symbol = Symbol::create(['code' => 'EURUSD', 'display_name' => 'Euro', 'asset_class' => 'forex', 'is_active' => true]);
        $from = CarbonImmutable::parse('2026-07-13 22:00:00', 'UTC');
        $to = CarbonImmutable::parse('2026-07-14 02:00:00', 'UTC');

        foreach ([$from, $from->addHour(), $from->addHours(2), $from->addHours(3)] as $time) {
            $this->candle($symbol->id, $time);
        }

        $service = app(MarketDataContinuityService::class);
        $healthy = $service->recordResult('dukascopy', 'EURUSD', 'H1', $from, $to, 4);
        $this->assertSame('healthy', $healthy->status);

        Candle::query()->where('symbol_id', $symbol->id)->where('time', $from->addHour())->delete();
        $pending = $service->recordResult('dukascopy', 'EURUSD', 'H1', $from, $to, 0);
        $this->assertSame('catching_up', $pending->status);
        $this->assertTrue($pending->pending_from_at->equalTo($from->addHour()));
    }

    public function test_m15_recovery_detects_a_missing_quarter_hour(): void
    {
        $symbol = Symbol::create(['code' => 'EURUSD', 'display_name' => 'Euro', 'asset_class' => 'forex', 'is_active' => true]);
        $from = CarbonImmutable::parse('2026-07-20 10:00:00', 'UTC');
        $to = CarbonImmutable::parse('2026-07-20 11:00:00', 'UTC');

        foreach ([$from, $from->addMinutes(15), $from->addMinutes(30), $from->addMinutes(45)] as $time) {
            $this->candle($symbol->id, $time, 'M15');
        }

        $service = app(MarketDataContinuityService::class);
        $healthy = $service->recordResult('dukascopy', 'EURUSD', 'M15', $from, $to, 4);
        $this->assertSame('healthy', $healthy->status);

        Candle::query()->where('symbol_id', $symbol->id)->where('timeframe', 'M15')->where('time', $from->addMinutes(30))->delete();
        $pending = $service->recordResult('dukascopy', 'EURUSD', 'M15', $from, $to, 0);
        $this->assertSame('catching_up', $pending->status);
        $this->assertTrue($pending->pending_from_at->equalTo($from->addMinutes(30)));
    }

    private function candle(int $symbolId, CarbonImmutable $time, string $timeframe = 'H1'): void
    {
        Candle::create([
            'symbol_id' => $symbolId,
            'timeframe' => $timeframe,
            'time' => $time,
            'open' => 1.1,
            'high' => 1.2,
            'low' => 1.0,
            'close' => 1.15,
            'volume' => 1,
            'provider' => 'dukascopy',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\AdvanceIntradayTrainingArchiveJob;
use App\Models\MarketSymbol;
use App\Models\MarketTrainingArchive;
use App\Services\MarketData\DukascopyMarketDataProvider;
use App\Services\MarketData\MarketTrainingDataService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class IntradayTrainingMaintenanceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_intraday_archive_checkpoint_is_unique_bounded_and_isolated_from_learning(): void
    {
        Cache::forget('market-maintenance:intraday-training:failure-cooldown');
        Cache::forget('system:intraday-training-maintenance-heartbeat');
        Artisan::shouldReceive('call')->once()->with('market-data:backfill-intraday-training', [
            '--symbol' => 'XAUUSD',
            '--chunk-days' => 3,
            '--max-chunks' => 1,
            '--dataset' => 'foundation_intraday_10y',
            '--provider' => 'dukascopy',
            '--transport' => 'jetta',
        ])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('checkpoint complete');

        $job = new AdvanceIntradayTrainingArchiveJob;
        $job->handle();

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('market-maintenance', $job->queue);
        $this->assertSame(600, $job->timeout);
        $this->assertSame(1, $job->tries);
        $this->assertSame(1200, $job->uniqueFor);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(
            'completed_checkpoint',
            data_get(Cache::get('system:intraday-training-maintenance-heartbeat'), 'status'),
        );
    }

    public function test_completed_immutable_archive_does_not_repeat_full_coverage_scans(): void
    {
        MarketSymbol::create([
            'symbol' => 'XAUUSD',
            'provider_symbol' => 'XAU_USD',
            'name' => 'Gold / US Dollar',
            'market_type' => 'forex',
            'is_active' => true,
        ]);
        $cutoff = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');
        $training = Mockery::mock(MarketTrainingDataService::class);
        $training->shouldReceive('trainingCutoff')->once()->andReturn($cutoff);
        $training->shouldReceive('ensureArchive')->times(3)->andReturnUsing(
            static fn (string $dataset, string $provider, string $symbol, string $timeframe) => new MarketTrainingArchive([
                'dataset_key' => $dataset,
                'provider' => $provider,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'status' => 'complete',
                'backfill_cursor_at' => $cutoff,
                'row_count' => 100,
            ]),
        );
        $training->shouldNotReceive('refreshCoverage');
        $provider = Mockery::mock(DukascopyMarketDataProvider::class);
        $provider->shouldNotReceive('fetchCandles');
        $this->app->instance(MarketTrainingDataService::class, $training);
        $this->app->instance(DukascopyMarketDataProvider::class, $provider);

        $this->artisan('market-data:backfill-intraday-training')
            ->expectsOutput('XAUUSD intraday training archive already complete.')
            ->assertSuccessful();
    }
}

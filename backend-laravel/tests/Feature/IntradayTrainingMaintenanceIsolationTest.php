<?php

namespace Tests\Feature;

use App\Jobs\AdvanceIntradayTrainingArchiveJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IntradayTrainingMaintenanceIsolationTest extends TestCase
{
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
}

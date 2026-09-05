<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Advances the isolated pre-2026 intraday archive outside the scheduler.
 *
 * Network download, aggregation and large upserts used to run inside the
 * singleton scheduler process. A slow provider day then froze scheduler
 * heartbeats and every learning/evolution dispatch behind it. This unique,
 * bounded maintenance job leaves the scheduler with dispatch-only work.
 */
class AdvanceIntradayTrainingArchiveJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 1200;

    public bool $failOnTimeout = true;

    public function __construct()
    {
        $this->onConnection((string) config('queue.default', 'redis'));
        $this->onQueue((string) config('services.lab_queue.market_maintenance_queue', 'market-maintenance'));
    }

    public function uniqueId(): string
    {
        return 'xauusd-foundation-intraday-backfill';
    }

    public function handle(): void
    {
        $cooldownKey = 'market-maintenance:intraday-training:failure-cooldown';
        if (Cache::has($cooldownKey)) {
            return;
        }

        $exitCode = Artisan::call('market-data:backfill-intraday-training', [
            '--symbol' => 'XAUUSD',
            '--chunk-days' => 3,
            '--max-chunks' => 1,
            '--dataset' => 'foundation_intraday_10y',
            '--provider' => 'dukascopy',
            '--transport' => 'jetta',
        ]);
        $output = trim(Artisan::output());
        if ($exitCode !== 0) {
            Cache::put($cooldownKey, true, now()->addMinutes(5));
            Log::warning('Bounded intraday training archive checkpoint failed; retry is cooled down.', [
                'exit_code' => $exitCode,
                'output' => substr($output, 0, 1500),
                'retry_after_seconds' => 300,
            ]);

            return;
        }

        Cache::put('system:intraday-training-maintenance-heartbeat', [
            'protocol' => 'intraday_training_maintenance_job_v1',
            'status' => 'completed_checkpoint',
            'completed_at' => now()->toIso8601String(),
            'output' => substr($output, 0, 500),
        ], now()->addHours(1));
    }
}

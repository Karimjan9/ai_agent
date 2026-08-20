<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunHeadlessScheduler extends Command
{
    protected $signature = 'schedule:headless-work';

    protected $description = 'Run scheduled callbacks in this PHP process without spawning Windows console windows.';

    public function handle(): int
    {
        // PM2 reloads on Windows can leave the previous PHP child alive for a
        // short time.  Laravel's per-command overlap locks do not protect the
        // scheduler loop itself, so two loops could both execute the same due
        // callbacks.  Keep one renewable Redis-backed process lease around the
        // entire loop.  The lease is operational coordination only; it never
        // changes a trading gate or evidence decision.
        $leaseKey = (string) config('services.scheduler.lease_key', 'trading:headless-scheduler:v1');
        $leaseSeconds = max(30, (int) config('services.scheduler.lease_seconds', 90));
        $duplicateWaitSeconds = max(1, (int) config('services.scheduler.duplicate_wait_seconds', 5));
        $lease = Cache::lock($leaseKey, $leaseSeconds);
        $lastDuplicateLog = 0.0;
        while (! $lease->get()) {
            // A duplicate/manual launch must stay completely passive rather
            // than exiting into a PM2 restart storm. It waits for the owner
            // or for a stale TTL to expire, but never runs schedule:run.
            $now = microtime(true);
            if (($now - $lastDuplicateLog) >= 30.0) {
                Log::warning('Headless scheduler lease is already owned; duplicate process is waiting passively.', [
                    'lease_key' => $leaseKey,
                    'pid' => getmypid(),
                ]);
                $lastDuplicateLog = $now;
            }
            sleep($duplicateWaitSeconds);
        }

        $lastMinute = null;
        $executedTicks = 0;
        // Zero is intentional: keep one long-lived scheduler process. A
        // positive value is an explicit bounded-rotation override. Treating
        // zero as one causes PM2 to restart PHP after every minute tick,
        // which can materialize a visible console window on Windows.
        $maxTicksPerProcess = max(0, (int) config('services.scheduler.max_ticks_per_process', 0));
        $lastLeaseRefresh = microtime(true);
        // A long-lived scheduler must not recycle after every heavy callback:
        // on Windows each PM2 recycle can briefly materialize a conhost. Set
        // a positive value only when an operator explicitly wants bounded
        // memory rotation; zero leaves lifecycle control to PM2/monitoring.
        $memoryLimitMb = max(0, (int) env('SCHEDULER_MEMORY_LIMIT_MB', 0));
        $memoryLimitBytes = $memoryLimitMb > 0 ? $memoryLimitMb * 1024 * 1024 : 0;
        $heartbeatSeconds = max(5, min($leaseSeconds - 5, (int) config('services.scheduler.heartbeat_seconds', 20)));

        try {
            Cache::put('system:scheduler-lease', [
                'protocol' => 'headless_scheduler_singleton_v1',
                'pid' => getmypid(),
                'lease_key' => $leaseKey,
                'started_at' => now()->toIso8601String(),
                'lease_seconds' => $leaseSeconds,
            ], now()->addSeconds($leaseSeconds));
            // Publish liveness as soon as the singleton lease is acquired.
            // Previously this key was written only after a successful
            // schedule:run call, so a healthy owner looked dead whenever a
            // single scheduled callback returned non-zero. Callback success
            // remains separately observable in the scheduler logs; this key
            // is strictly process/lease liveness and never evidence.
            Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(10));

            while (true) {
                $now = microtime(true);
                if (($now - $lastLeaseRefresh) >= $heartbeatSeconds) {
                    try {
                        if (! $lease->refresh($leaseSeconds)) {
                            Log::critical('Headless scheduler lease was lost; exiting for a clean supervisor restart.', [
                                'lease_key' => $leaseKey,
                                'pid' => getmypid(),
                            ]);

                            return self::SUCCESS;
                        }
                    } catch (Throwable $exception) {
                        // Continuing after a failed refresh could create two
                        // active schedulers once the old TTL expires. Fail
                        // closed and let PM2 restart one clean owner.
                        Log::critical('Headless scheduler lease refresh failed; exiting.', [
                            'lease_key' => $leaseKey,
                            'pid' => getmypid(),
                            'exception' => $exception,
                        ]);

                        return self::FAILURE;
                    }
                    $lastLeaseRefresh = $now;
                    Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(10));
                    Cache::put('system:scheduler-lease', [
                        'protocol' => 'headless_scheduler_singleton_v1',
                        'pid' => getmypid(),
                        'lease_key' => $leaseKey,
                        'heartbeat_at' => now()->toIso8601String(),
                        'lease_seconds' => $leaseSeconds,
                    ], now()->addSeconds($leaseSeconds));
                }

                $minute = CarbonImmutable::now()->format('Y-m-d H:i');

                if ($minute !== $lastMinute) {
                    $lastMinute = $minute;
                    // Persist the minute claim so a bounded-memory restart
                    // cannot immediately execute the same schedule minute a
                    // second time. The in-process marker above only protects
                    // one PHP lifetime; the cache key spans the PM2 restart.
                    if ($this->claimMinute($minute)) {
                        try {
                            $exitCode = Artisan::call('schedule:run', ['--whisper' => true]);
                            if ($exitCode !== 0) {
                                Log::warning('Headless scheduler tick returned a non-zero exit code.', [
                                    'minute' => $minute,
                                    'exit_code' => $exitCode,
                                ]);
                            } else {
                                Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(10));
                            }
                        } catch (Throwable $exception) {
                            // A transient MySQL deadlock must not kill the only
                            // scheduler process. Mark this minute consumed so
                            // the loop does not hammer the same lock; the next
                            // minute retries the normal schedule tick.
                            Log::warning('Headless scheduler tick failed; retrying on the next minute.', [
                                'minute' => $minute,
                                'exception' => $exception,
                            ]);
                        }

                        $executedTicks++;
                        gc_collect_cycles();

                        if ($maxTicksPerProcess > 0 && $executedTicks >= $maxTicksPerProcess) {
                            Log::info('Headless scheduler process completed isolated tick.', [
                                'executed_ticks' => $executedTicks,
                                'max_ticks_per_process' => $maxTicksPerProcess,
                            ]);

                            return self::SUCCESS;
                        }

                        $memoryBytes = memory_get_usage(true);
                        if ($memoryLimitBytes > 0 && $memoryBytes >= $memoryLimitBytes) {
                            Log::warning('Headless scheduler reached its bounded memory limit; exiting for a clean supervisor restart.', [
                                'minute' => $minute,
                                'memory_bytes' => $memoryBytes,
                                'memory_limit_mb' => $memoryLimitMb,
                            ]);

                            return self::SUCCESS;
                        }
                    }
                }

                usleep(250_000);
            }
        } finally {
            try {
                // Never erase a replacement owner's heartbeat after this
                // process has lost/expired its lease.
                if ($lease->isOwnedByCurrentProcess()) {
                    Cache::forget('system:scheduler-lease');
                }
                $lease->release();
            } catch (Throwable $exception) {
                Log::warning('Headless scheduler lease release failed during shutdown.', [
                    'lease_key' => $leaseKey,
                    'pid' => getmypid(),
                    'exception' => $exception,
                ]);
            }
        }
    }

    private function claimMinute(string $minute): bool
    {
        return Cache::add(
            'system:scheduler-tick:'.$minute,
            [
                'protocol' => 'headless_scheduler_tick_claim_v1',
                'minute' => $minute,
                'pid' => getmypid(),
            ],
            now()->addMinutes(10),
        );
    }
}

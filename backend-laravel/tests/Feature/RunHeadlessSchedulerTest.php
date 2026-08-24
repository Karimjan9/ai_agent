<?php

namespace Tests\Feature;

use App\Console\Commands\RunHeadlessScheduler;
use App\Services\RuntimeMonitoringService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class RunHeadlessSchedulerTest extends TestCase
{
    public function test_scheduler_claims_a_minute_once_across_a_process_restart(): void
    {
        $minute = '2099-01-02 03:04';
        $key = 'system:scheduler-tick:'.$minute;
        Cache::forget($key);

        try {
            $claim = new ReflectionMethod(RunHeadlessScheduler::class, 'claimMinute');
            $claim->setAccessible(true);

            $this->assertTrue($claim->invoke(app(RunHeadlessScheduler::class), $minute));
            $this->assertFalse($claim->invoke(app(RunHeadlessScheduler::class), $minute));
            $this->assertTrue($claim->invoke(app(RunHeadlessScheduler::class), '2099-01-02 03:05'));
        } finally {
            Cache::forget($key);
            Cache::forget('system:scheduler-tick:2099-01-02 03:05');
        }
    }

    public function test_runtime_monitor_reports_the_real_age_of_a_stale_scheduler_heartbeat(): void
    {
        CarbonImmutable::setTestNow('2026-08-24 06:00:00 UTC');
        Cache::put('system:scheduler-heartbeat', '2026-08-24T05:40:00+00:00', now()->addMinute());

        try {
            $check = app(RuntimeMonitoringService::class)->inspect()['checks']['scheduler'];

            $this->assertSame('critical', $check['status']);
            $this->assertSame(1200, $check['metrics']['heartbeat_age_seconds']);
        } finally {
            Cache::forget('system:scheduler-heartbeat');
            CarbonImmutable::setTestNow();
        }
    }

    public function test_scheduler_can_recover_a_stale_lease_from_a_dead_process_on_the_same_host(): void
    {
        $leaseKey = 'test:headless-scheduler:stale-owner';
        $owner = Cache::lock($leaseKey, 900);
        $this->assertTrue($owner->get());
        Cache::put('system:scheduler-lease', [
            'lease_key' => $leaseKey,
            'pid' => 99999999,
            'hostname' => (string) (gethostname() ?: php_uname('n')),
            'heartbeat_at' => now()->subMinutes(5)->toIso8601String(),
        ], now()->addMinutes(15));

        try {
            $method = new ReflectionMethod(RunHeadlessScheduler::class, 'recoverStaleLocalLease');
            $method->setAccessible(true);
            $contender = Cache::lock($leaseKey, 900);

            $this->assertTrue($method->invoke(app(RunHeadlessScheduler::class), $contender, $leaseKey, 30));
            $this->assertTrue($contender->get());
            $contender->release();
        } finally {
            $owner->forceRelease();
            Cache::forget('system:scheduler-lease');
            Cache::forget('system:scheduler-heartbeat');
        }
    }

    public function test_scheduler_runs_each_tick_in_an_isolated_child_process(): void
    {
        $method = new ReflectionMethod(RunHeadlessScheduler::class, 'scheduleProcess');
        $method->setAccessible(true);
        $process = $method->invoke(app(RunHeadlessScheduler::class));

        $commandLine = $process->getCommandLine();
        $this->assertStringContainsString(PHP_BINARY, $commandLine);
        $this->assertStringContainsString(base_path('artisan'), $commandLine);
        $this->assertStringContainsString('schedule:run', $commandLine);
        $this->assertStringContainsString('--whisper', $commandLine);
        $this->assertTrue($process->isOutputDisabled());
        $this->assertNull($process->getTimeout());
    }

    public function test_lease_refresh_preserves_the_original_process_start_time(): void
    {
        $leaseKey = 'test:headless-scheduler:refresh-start';
        $startedAt = '2026-08-24T05:00:00+00:00';
        Cache::put('system:scheduler-lease', [
            'lease_key' => $leaseKey,
            'started_at' => $startedAt,
        ], now()->addMinute());

        try {
            $method = new ReflectionMethod(RunHeadlessScheduler::class, 'refreshLease');
            $method->setAccessible(true);
            $lease = new class
            {
                public function refresh(int $seconds): bool
                {
                    return $seconds > 0;
                }
            };

            $this->assertTrue($method->invoke(app(RunHeadlessScheduler::class), $lease, $leaseKey, 90));
            $this->assertSame($startedAt, data_get(Cache::get('system:scheduler-lease'), 'started_at'));
        } finally {
            Cache::forget('system:scheduler-lease');
            Cache::forget('system:scheduler-heartbeat');
        }
    }
}

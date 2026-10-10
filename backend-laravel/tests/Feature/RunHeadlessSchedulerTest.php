<?php

namespace Tests\Feature;

use App\Console\Commands\RunHeadlessScheduler;
use App\Services\HiddenProcessRunnerService;
use App\Services\RuntimeMonitoringService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

class RunHeadlessSchedulerTest extends TestCase
{
    public function test_hidden_windows_pid_probe_preserves_live_dead_and_unknown_states(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows tasklist transport only.');
        }
        $method = new ReflectionMethod(RunHeadlessScheduler::class, 'localProcessIsRunning');
        foreach ([
            [['exit_code' => 0, 'stdout' => '"php.exe","23456","Console","1","100 K"', 'stderr' => ''], true],
            [['exit_code' => 0, 'stdout' => 'INFO: No tasks are running which match the specified criteria.', 'stderr' => ''], false],
            [['exit_code' => 124, 'stdout' => '', 'stderr' => ''], null],
            [new \RuntimeException('Unavailable broker'), null],
        ] as [$result, $expected]) {
            $runner = \Mockery::mock(HiddenProcessRunnerService::class);
            $expectation = $runner->shouldReceive('run')->once()->with(
                ['tasklist', '/FI', 'PID eq 23456', '/FO', 'CSV', '/NH'], 3);
            if ($result instanceof \Throwable) {
                $expectation->andThrow($result);
            } else {
                $expectation->andReturn($result);
            }
            $this->app->instance(HiddenProcessRunnerService::class, $runner);
            $this->assertSame($expected, $method->invoke(app(RunHeadlessScheduler::class), 23456));
        }
    }

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
        Log::spy();
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
            Log::shouldHaveReceived('warning')->once()->withArgs(
                fn (string $message, array $context): bool => $message === 'Recovered a stale headless scheduler lease from a dead local process.'
                    && $context['lease_key'] === $leaseKey
                    && $context['stale_pid'] === 99999999,
            );
            Log::shouldNotHaveReceived('critical');
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
